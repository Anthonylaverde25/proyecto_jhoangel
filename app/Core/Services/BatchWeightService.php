<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Interfaces\IBatchRepository;
use App\Core\Interfaces\ICaravanRepository;

/**
 * Domain Service to handle Batch weight calculations based on individual animal data.
 */
class BatchWeightService
{
    public function __construct(
        private readonly IBatchRepository $batchRepository,
        private readonly ICaravanRepository $caravanRepository
    ) {
    }

    /**
     * Recalculates the average weight of a batch after a caravan addition.
     * 
     * Formula: ((Current_Avg * Old_Count) + New_Weight) / New_Count
     */
    public function updateBatchWeightAfterAddition(int $batchId, float $newAnimalWeight): void
    {
        $batch = $this->batchRepository->findById($batchId);
        if (!$batch) {
            return;
        }

        // We assume this is called AFTER the caravan has been saved to the database
        $newCount = $this->caravanRepository->countByBatch($batchId);
        if ($newCount === 0) {
            return;
        }

        $currentAvg = $batch->getCurrentWeight() ?? 0.0;
        $oldCount = $newCount - 1;

        if ($oldCount <= 0) {
            // First animal in the batch
            $newAvg = $newAnimalWeight;
        } else {
            $totalWeightBefore = $currentAvg * $oldCount;
            $newAvg = ($totalWeightBefore + $newAnimalWeight) / $newCount;
        }

        $batch->setCurrentWeight($newAvg);
        $this->batchRepository->save($batch);
    }

    /**
     * Recalculates the average weight of a batch after a caravan removal.
     * 
     * Formula: ((Current_Avg * Old_Count) - Removed_Weight) / New_Count
     */
    public function updateBatchWeightAfterRemoval(int $batchId, float $removedAnimalWeight): void
    {
        $batch = $this->batchRepository->findById($batchId);
        if (!$batch) {
            return;
        }

        // We assume this is called AFTER the caravan has been removed or reassigned
        $newCount = $this->caravanRepository->countByBatch($batchId);
        $oldCount = $newCount + 1;

        if ($newCount <= 0) {
            $batch->setCurrentWeight(0.0);
        } else {
            $currentAvg = $batch->getCurrentWeight() ?? 0.0;
            $totalWeightBefore = $currentAvg * $oldCount;
            $newAvg = ($totalWeightBefore - $removedAnimalWeight) / $newCount;
            $batch->setCurrentWeight(max(0, $newAvg));
        }

        $this->batchRepository->save($batch);
    }

    /**
     * Recalculates the average weight when an animal that was already in the batch
     * changes its individual weight.
     * 
     * Formula: ((Current_Avg * Count) - Old_Weight + New_Weight) / Count
     */
    public function updateBatchWeightAfterWeightChange(int $batchId, float $oldWeight, float $newWeight): void
    {
        $batch = $this->batchRepository->findById($batchId);
        if (!$batch) {
            return;
        }

        $count = $this->caravanRepository->countByBatch($batchId);
        if ($count <= 0) {
            return;
        }

        $currentAvg = $batch->getCurrentWeight() ?? 0.0;
        $totalWeightBefore = $currentAvg * $count;
        $newAvg = ($totalWeightBefore - $oldWeight + $newWeight) / $count;

        $batch->setCurrentWeight(max(0, $newAvg));
        $this->batchRepository->save($batch);
    }
}
