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
     * Recalculates the average weight of a batch based on current animal weights.
     */
    public function recalculateBatchWeight(int $batchId): void
    {
        $batch = $this->batchRepository->findById($batchId);
        if (!$batch) {
            return;
        }

        $newAvg = $this->caravanRepository->getAverageWeightByBatch($batchId);
        
        $batch->setCurrentWeight($newAvg);
        $this->batchRepository->save($batch);
    }

    /**
     * Legacy methods kept for compatibility during transition if needed, 
     * but now they all just trigger a full recalculation.
     */
    public function updateBatchWeightAfterAddition(int $batchId, float $newAnimalWeight): void
    {
        $this->recalculateBatchWeight($batchId);
    }

    public function updateBatchWeightAfterRemoval(int $batchId, float $removedAnimalWeight): void
    {
        $this->recalculateBatchWeight($batchId);
    }

    public function updateBatchWeightAfterWeightChange(int $batchId, float $oldWeight, float $newWeight): void
    {
        $this->recalculateBatchWeight($batchId);
    }
}
