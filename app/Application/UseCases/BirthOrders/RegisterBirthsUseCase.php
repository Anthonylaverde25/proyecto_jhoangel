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
 * "Registrar partos": the calvings already happened and the system learns them afterwards. The order
 * is born executed.
 *
 * It is created issued and executed through the one path that registers calvings, inside a single
 * transaction: if a calving fails, the order never existed. Every female sent has her outcome — a
 * registration leaves nothing pending.
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
     * @return array{order: BirthOrderEntity, result: array<string, mixed>}
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

        return DB::transaction(function () use ($dto, $fieldDataByMotherId): array {
            $order = $this->factory->create($dto, TransferOrderKind::REGISTERED, 'Partos registrados después del hecho');

            $result = ($this->processPar01)($this->submissions->fromOrder(
                $order,
                Par01SubmissionDTO::ORIGIN_REGISTRATION,
                $dto->requestedByUserId,
                $fieldDataByMotherId
            ));

            return [
                'order' => $this->repository->findById((int) $order->getId(), $dto->companyId)
                    ?? throw BirthOrderDomainException::notFound(),
                'result' => $result,
            ];
        });
    }
}
