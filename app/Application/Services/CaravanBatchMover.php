<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Core\Entities\BatchEntity;
use App\Core\Entities\CaravanEntity;
use App\Core\Entities\CaravanMovementEntity;
use App\Core\Enums\BatchWeightCause;
use App\Core\Interfaces\ICaravanMovementRepository;
use App\Core\Interfaces\ICaravanRepository;
use App\Core\Interfaces\ICompanyRepository;
use App\Core\Interfaces\IFarmRepository;
use App\Core\Services\BatchWeightService;

/**
 * Moves animals into a batch and records one movement each, closing and reopening the weight
 * series of every batch whose composition changes. Shared by the quick transfer (TRANSFER) and
 * the death (DEATH): the movement type is what tells them apart downstream.
 */
final class CaravanBatchMover
{
    public function __construct(
        private readonly ICaravanRepository $caravanRepository,
        private readonly ICaravanMovementRepository $movementRepository,
        private readonly IFarmRepository $farmRepository,
        private readonly ICompanyRepository $companyRepository,
        private readonly BatchWeightService $batchWeightService
    ) {
    }

    /**
     * @param array<int, CaravanEntity> $caravans by id, read before anything moves
     * @return array<int, int> caravan id => movement id
     */
    public function move(array $caravans, BatchEntity $targetBatch, \DateTimeInterface $movementDate, string $type, string $observation): array
    {
        $targetBatchId = (int) $targetBatch->getId();

        // The source batches and the composition they still hold have to be read now: once the
        // animals change batch the previous membership is gone.
        $sourceBatchIds = [];
        foreach ($caravans as $caravan) {
            $previousBatchId = $caravan->getBatchId();
            if ($previousBatchId !== null && $previousBatchId !== $targetBatchId) {
                $sourceBatchIds[$previousBatchId] = true;
            }
        }

        // Closing point of every composition about to change, on BOTH sides: without it the
        // stretch from the last weighing to the movement would blend growth with entries.
        foreach (array_keys($sourceBatchIds) as $sourceBatchId) {
            $this->batchWeightService->snapshotBeforeMovement((int) $sourceBatchId, $movementDate);
        }
        $this->batchWeightService->snapshotBeforeMovement($targetBatchId, $movementDate);

        $renspa = $this->renspaOf($targetBatch);
        $movementIds = [];

        foreach ($caravans as $caravanId => $caravan) {
            $effectiveRenspa = $renspa;
            if ($effectiveRenspa === '' && $caravan->getCompanyId() !== null) {
                $effectiveRenspa = $this->companyRepository->findById($caravan->getCompanyId())?->getRenspa() ?? '';
            }

            $this->caravanRepository->updateBatchAndCategory($caravanId, $targetBatchId, null);

            $movement = $this->movementRepository->save(new CaravanMovementEntity(
                id: null,
                caravanId: $caravanId,
                companyId: $caravan->getCompanyId(),
                renspa: $effectiveRenspa,
                type: $type,
                movementDate: $movementDate,
                observations: $observation,
                fromBatchId: $caravan->getBatchId(),
                toBatchId: $targetBatchId
            ));

            $movementIds[$caravanId] = (int) $movement->getId();
        }

        // Opening point of the new composition on both sides: the distance to the closing point
        // is, by construction, the movement and not elapsed time.
        foreach (array_keys($sourceBatchIds) as $sourceBatchId) {
            $this->batchWeightService->recalculateBatchWeight((int) $sourceBatchId, BatchWeightCause::MOVEMENT_OUT, $movementDate);
        }
        $this->batchWeightService->recalculateBatchWeight($targetBatchId, BatchWeightCause::MOVEMENT_IN, $movementDate);

        return $movementIds;
    }

    private function renspaOf(BatchEntity $batch): string
    {
        $farmId = $batch->getFarmId();

        return $farmId !== null ? ($this->farmRepository->findById($farmId)?->getRenspa() ?? '') : '';
    }
}
