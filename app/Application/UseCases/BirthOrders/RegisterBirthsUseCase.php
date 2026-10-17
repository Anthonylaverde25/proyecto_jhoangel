<?php

declare(strict_types=1);

namespace App\Application\UseCases\BirthOrders;

use App\Application\DTOs\BirthOrders\BirthFieldData;
use App\Application\DTOs\BirthOrders\EmitBirthOrderDTO;
use App\Application\DTOs\Par01\Par01SubmissionDTO;
use App\Application\Services\BirthOrderFactory;
use App\Application\Services\BirthOrderSubmissionBuilder;
use App\Application\UseCases\WorkTemplates\ProcessPar01SubmissionUseCase;
use App\Core\Entities\BirthOrderEntity;
use App\Core\Enums\TransferOrderKind;
use App\Core\Exceptions\BirthOrderDomainException;
use App\Core\Exceptions\DomainException;
use App\Core\Exceptions\Par01ValidationException;
use App\Core\Interfaces\IBirthOrderRepository;
use Illuminate\Support\Facades\DB;

/**
 * "Registrar partos": the calvings already happened and the system learns them afterwards, without
 * the user having to know which order each female is in.
 *
 * A female held by an open order has her calving registered in that order (it advances, or
 * completes); the rest go into a new order, born executed. Everything happens in one transaction:
 * if a calving fails, no order changes and no order is created. Every female sent has her outcome —
 * a registration leaves nothing pending.
 */
final class RegisterBirthsUseCase
{
    public function __construct(
        private readonly IBirthOrderRepository $repository,
        private readonly BirthOrderFactory $factory,
        private readonly BirthOrderSubmissionBuilder $submissions,
        private readonly ProcessPar01SubmissionUseCase $processPar01
    ) {
    }

    /**
     * @param array<int, BirthFieldData> $fieldDataByMotherId
     * @return list<array{order: BirthOrderEntity, result: array<string, mixed>, created: bool}> the new order first, then the existing ones
     *
     * @throws BirthOrderDomainException
     * @throws Par01ValidationException
     * @throws DomainException
     */
    public function __invoke(EmitBirthOrderDTO $dto, array $fieldDataByMotherId): array
    {
        $unresolved = array_filter(
            $dto->motherIds,
            fn (int $id) => !($fieldDataByMotherId[$id] ?? null)?->hasOutcome()
        );

        if ($unresolved !== []) {
            throw BirthOrderDomainException::domainError(
                count($unresolved) . ' vientre(s) sin resultado: en un registro de partos cada vientre dice qué pasó. Quitá los que todavía no parieron.',
                'OUTCOME_MISSING'
            );
        }

        [$free, $heldByCode] = $this->splitByOpenOrder($dto);

        return DB::transaction(function () use ($dto, $fieldDataByMotherId, $free, $heldByCode): array {
            $registered = [];

            if ($free !== []) {
                $registered[] = $this->registerInNewOrder($dto, $free, $fieldDataByMotherId);
            }

            foreach ($heldByCode as $code => $motherIds) {
                $registered[] = $this->registerInOpenOrder($code, $motherIds, $dto, $fieldDataByMotherId);
            }

            return $registered;
        });
    }

    /**
     * @return array{0: list<int>, 1: array<string, list<int>>} the free mothers, and the held ones by order code
     */
    private function splitByOpenOrder(EmitBirthOrderDTO $dto): array
    {
        $openMothers = $this->repository->openMothers($dto->companyId);
        $free = [];
        $heldByCode = [];

        foreach ($dto->motherIds as $motherId) {
            $code = $openMothers[$motherId] ?? null;

            if ($code === null) {
                $free[] = $motherId;
            } else {
                $heldByCode[$code][] = $motherId;
            }
        }

        return [$free, $heldByCode];
    }

    /**
     * @param list<int> $motherIds
     * @param array<int, BirthFieldData> $fieldDataByMotherId
     * @return array{order: BirthOrderEntity, result: array<string, mixed>, created: bool}
     */
    private function registerInNewOrder(EmitBirthOrderDTO $dto, array $motherIds, array $fieldDataByMotherId): array
    {
        $order = $this->factory->create(
            new EmitBirthOrderDTO(
                companyId: $dto->companyId,
                requestedByUserId: $dto->requestedByUserId,
                periodStart: $dto->periodStart,
                periodEnd: $dto->periodEnd,
                responsable: $dto->responsable,
                observations: $dto->observations,
                motherIds: $motherIds,
                issue: true
            ),
            TransferOrderKind::REGISTERED,
            'Partos registrados después del hecho'
        );

        $result = ($this->processPar01)($this->submissions->fromOrder(
            $order,
            Par01SubmissionDTO::ORIGIN_REGISTRATION,
            $dto->requestedByUserId,
            array_intersect_key($fieldDataByMotherId, array_flip($motherIds))
        ));

        return ['order' => $this->reload($order, $dto->companyId), 'result' => $result, 'created' => true];
    }

    /**
     * @param list<int> $motherIds
     * @param array<int, BirthFieldData> $fieldDataByMotherId
     * @return array{order: BirthOrderEntity, result: array<string, mixed>, created: bool}
     */
    private function registerInOpenOrder(string $code, array $motherIds, EmitBirthOrderDTO $dto, array $fieldDataByMotherId): array
    {
        $order = $this->repository->findByCode($code, $dto->companyId) ?? throw BirthOrderDomainException::notFound();

        $result = ($this->processPar01)($this->submissions->fromOrder(
            $order,
            Par01SubmissionDTO::ORIGIN_SCREEN,
            $dto->requestedByUserId,
            array_intersect_key($fieldDataByMotherId, array_flip($motherIds))
        ));

        return ['order' => $this->reload($order, $dto->companyId), 'result' => $result, 'created' => false];
    }

    private function reload(BirthOrderEntity $order, int $companyId): BirthOrderEntity
    {
        return $this->repository->findById((int) $order->getId(), $companyId) ?? throw BirthOrderDomainException::notFound();
    }
}
