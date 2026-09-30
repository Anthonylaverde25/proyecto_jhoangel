<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Application\DTOs\BirthOrders\EmitBirthOrderDTO;
use App\Core\Entities\BirthOrderAnimalEntity;
use App\Core\Entities\BirthOrderEntity;
use App\Core\Enums\TransferOrderKind;
use App\Core\Exceptions\BirthOrderDomainException;
use App\Core\Interfaces\IBirthOrderRepository;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Builds and saves a new birth order: roll, commitment check, server code.
 *
 * Shared by "Nueva orden de parición", "Registrar partos" and the sheet that arrives without an
 * order. What each of them allows on top stays in its own use case.
 */
final class BirthOrderFactory
{
    private const CODE_ATTEMPTS = 3;

    public function __construct(
        private readonly IBirthOrderRepository $repository,
        private readonly BirthOrderRosterBuilder $roster,
        private readonly BirthOrderCodeGenerator $codeGenerator
    ) {
    }

    /**
     * @param BirthOrderAnimalEntity[]|null $animals a roll already built by the caller (the sheet
     *        that arrived without an order, whose females need not be pregnant); null builds it from
     *        the DTO.
     *
     * @throws BirthOrderDomainException
     */
    public function create(
        EmitBirthOrderDTO $dto,
        TransferOrderKind $kind,
        string $reason,
        bool $checkCommitment = true,
        ?array $animals = null
    ): BirthOrderEntity {
        $animals ??= $this->roster->build($dto);

        if ($dto->issue && $checkCommitment) {
            $this->roster->assertNotCommitted(
                array_map(fn (BirthOrderAnimalEntity $a) => $a->getMotherCaravanId(), $animals),
                $dto->companyId
            );
        }

        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(function () use ($dto, $animals, $kind, $reason): BirthOrderEntity {
                    $order = BirthOrderEntity::create(
                        issued: $dto->issue,
                        companyId: $dto->companyId,
                        code: $this->codeGenerator->next($dto->companyId, new \DateTimeImmutable()),
                        kind: $kind,
                        periodStart: $dto->periodStart,
                        periodEnd: $dto->periodEnd,
                        requestedByUserId: $dto->requestedByUserId,
                        responsable: $dto->responsable,
                        observations: $dto->observations,
                        animals: $animals
                    );

                    return $this->repository->save($order, $dto->requestedByUserId, $reason);
                });
            } catch (UniqueConstraintViolationException $e) {
                // Two orders created in the same instant took the same number. Take the next one.
                if ($attempt >= self::CODE_ATTEMPTS) {
                    throw $e;
                }
            }
        }
    }
}
