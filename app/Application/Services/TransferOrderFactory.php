<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Application\DTOs\TransferOrders\EmitTransferOrderDTO;
use App\Core\Entities\TransferOrderAnimalEntity;
use App\Core\Entities\TransferOrderEntity;
use App\Core\Enums\TransferOrderKind;
use App\Core\Exceptions\TransferOrderDomainException;
use App\Core\Interfaces\ITransferOrderRepository;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Builds and saves a new transfer order: roster, commitment check, server code.
 *
 * Shared by "Nueva orden" and "Registrar transferencia". What each of them allows on top (one
 * active order per batch, execution in the same breath) stays in its own use case.
 */
final class TransferOrderFactory
{
    private const CODE_ATTEMPTS = 3;

    public function __construct(
        private readonly ITransferOrderRepository $repository,
        private readonly TransferOrderRosterBuilder $roster,
        private readonly TransferOrderCodeGenerator $codeGenerator
    ) {
    }

    /**
     * Only an issued order checks that its animals are not committed elsewhere: a draft commits
     * nothing, and the check runs again when it is issued.
     *
     * @throws TransferOrderDomainException
     */
    public function create(EmitTransferOrderDTO $dto, TransferOrderKind $kind, string $reason): TransferOrderEntity
    {
        $sourceBatch = $this->roster->sourceBatch($dto->sourceBatchId);
        [$destinations, $animals] = $this->roster->build($dto, $sourceBatch);

        if ($dto->issue) {
            $this->roster->assertNotCommitted(
                array_map(fn (TransferOrderAnimalEntity $a) => $a->getCaravanId(), $animals),
                $dto->companyId
            );
        }

        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(function () use ($dto, $destinations, $animals, $kind, $reason): TransferOrderEntity {
                    $order = TransferOrderEntity::create(
                        issued: $dto->issue,
                        companyId: $dto->companyId,
                        sourceBatchId: $dto->sourceBatchId,
                        destinationActivityId: $dto->destinationActivityId,
                        code: $this->codeGenerator->next($dto->companyId, new \DateTimeImmutable()),
                        destinationMode: $dto->destinationMode,
                        movementDate: $dto->movementDate,
                        requestedByUserId: $dto->requestedByUserId,
                        responsable: $dto->responsable,
                        observations: $dto->observations,
                        destinations: $destinations,
                        animals: $animals,
                        kind: $kind,
                        categoryMode: $dto->categoryMode
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
