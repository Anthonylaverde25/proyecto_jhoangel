<?php

declare(strict_types=1);

namespace App\Application\UseCases\ServiceOrders;

use App\Core\Entities\ServiceOrderEntity;
use App\Core\Exceptions\ServiceOrderDomainException;
use App\Core\Enums\BatchWeightCause;
use App\Core\Interfaces\IServiceOrderRepository;
use App\Core\Interfaces\ICaravanRepository;
use App\Core\Services\BatchWeightService;

final class CompleteServiceOrderUseCase
{
    public function __construct(
        private readonly IServiceOrderRepository $repository,
        private readonly ICaravanRepository $caravanRepository,
        private readonly BatchWeightService $batchWeightService
    ) {
    }

    /**
     * @throws ServiceOrderDomainException
     */
    public function __invoke(int $id, int $companyId, int $userId, ?string $observations = null, ?int $targetBatchId = null): ServiceOrderEntity
    {
        $entity = $this->repository->findById($id, $companyId);
        if ($entity === null) {
            throw ServiceOrderDomainException::domainError("Service order not found");
        }

        // 1. Transfer unpregnant females to target batch if targetBatchId is provided
        if ($targetBatchId !== null) {
            // Resolved in a first pass, BEFORE anything moves: once the animals have
            // changed batch the previous membership is gone, and with it the chance to
            // close the composition that is about to end.
            $toMove = [];
            $sourceBatchIds = [];

            foreach ($entity->getFemaleCaravanIds() as $femaleId) {
                $caravan = $this->caravanRepository->findById($femaleId);

                if ($caravan === null || $caravan->hasActiveGestation()) {
                    continue;
                }

                $toMove[] = $caravan;

                $sourceBatchId = $caravan->getBatchId();
                if ($sourceBatchId !== null && $sourceBatchId !== $targetBatchId) {
                    $sourceBatchIds[$sourceBatchId] = true;
                }
            }

            if ($toMove !== []) {
                // Closing point of every composition about to change, anchored on today.
                foreach (array_keys($sourceBatchIds) as $sourceBatchId) {
                    $this->batchWeightService->snapshotBeforeMovement((int) $sourceBatchId);
                }
                $this->batchWeightService->snapshotBeforeMovement($targetBatchId);

                foreach ($toMove as $caravan) {
                    // The category travels unchanged: what moves is the animal, not its class.
                    $this->caravanRepository->updateBatchAndCategory(
                        $caravan->getId(),
                        $targetBatchId,
                        $caravan->getCategoryId(),
                        $caravan->getSubcategoryId()
                    );
                }

                // The aggregate weight of a batch is derived from its current membership:
                // moving animals without recalculating leaves both batches showing the
                // average of a set that no longer exists.
                foreach (array_keys($sourceBatchIds) as $sourceBatchId) {
                    $this->batchWeightService->recalculateBatchWeight((int) $sourceBatchId, BatchWeightCause::MOVEMENT_OUT);
                }
                $this->batchWeightService->recalculateBatchWeight($targetBatchId, BatchWeightCause::MOVEMENT_IN);
            }
        }

        // 2. Validate that all female caravans remaining in the service batch have an active gestation
        foreach ($entity->getFemaleCaravanIds() as $femaleId) {
            $caravan = $this->caravanRepository->findById($femaleId);
            if ($caravan === null) {
                throw ServiceOrderDomainException::domainError("Caravan with ID {$femaleId} not found.");
            }
            if ($caravan->getBatchId() === $entity->getBatchId() && !$caravan->hasActiveGestation()) {
                throw ServiceOrderDomainException::domainError(
                    "Cannot close service order. Caravan {$caravan->getIdentification()->getValue()} is empty and remains in the service batch. You must move it to another batch."
                );
            }
        }

        $entity->complete($observations);

        return $this->repository->save($entity, $userId);
    }
}
