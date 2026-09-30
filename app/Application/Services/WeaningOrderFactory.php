<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Application\DTOs\WeaningOrders\EmitWeaningOrderDTO;
use App\Core\Entities\WeaningOrderAnimalEntity;
use App\Core\Entities\WeaningOrderEntity;
use App\Core\Enums\TransferOrderKind;
use App\Core\Exceptions\WeaningOrderDomainException;
use App\Core\Interfaces\IWeaningOrderRepository;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Builds and saves a new weaning order: roster, commitment check, server code.
 *
 * Shared by "Nueva orden de destete", "Registrar destete" and the sheet that arrives without an
 * order. What each of them allows on top stays in its own use case.
 */
final class WeaningOrderFactory
{
    private const CODE_ATTEMPTS = 3;

    public function __construct(
        private readonly IWeaningOrderRepository $repository,
        private readonly WeaningOrderRosterBuilder $roster,
        private readonly WeaningOrderCodeGenerator $codeGenerator
    ) {
    }

    /**
     * Only an issued order checks that its calves are not committed elsewhere, unless the caller
     * says otherwise: a draft commits nothing, and a sheet that already happened without an order
     * never had that rule.
     *
     * @throws WeaningOrderDomainException
     */
    public function create(
        EmitWeaningOrderDTO $dto,
        TransferOrderKind $kind,
        string $reason,
        bool $checkCommitment = true
    ): WeaningOrderEntity {
        $activityId = $this->roster->destinationActivityId($dto->companyId);
        [$destinations, $animals] = $this->roster->build($dto, $activityId);

        if ($dto->issue && $checkCommitment) {
            $this->roster->assertNotCommitted(
                array_map(fn (WeaningOrderAnimalEntity $a) => $a->getCaravanId(), $animals),
                $dto->companyId
            );
        }

        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(function () use ($dto, $destinations, $animals, $kind, $reason, $activityId): WeaningOrderEntity {
                    $order = WeaningOrderEntity::create(
                        issued: $dto->issue,
                        companyId: $dto->companyId,
                        code: $this->codeGenerator->next($dto->companyId, new \DateTimeImmutable()),
                        kind: $kind,
                        destinationMode: $dto->destinationMode,
                        categoryMode: $dto->categoryMode,
                        destinationActivityId: $activityId,
                        weaningType: $dto->weaningType,
                        weaningDate: $dto->weaningDate,
                        requestedByUserId: $dto->requestedByUserId,
                        responsable: $dto->responsable,
                        observations: $dto->observations,
                        destinations: $destinations,
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
