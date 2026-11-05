<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Enums\BatchWeightCause;
use App\Core\Enums\WeighingTypeCode;
use App\Core\Interfaces\IBatchRepository;
use App\Core\Interfaces\ICaravanRepository;

/**
 * Domain Service to handle Batch weight calculations based on individual animal data.
 *
 * The aggregate weight of a batch is a DERIVED value of the animals it currently holds:
 * it is never declared and left to age. When animals are transferred the aggregate moves
 * for logistical reasons, not biological ones, and the series has to be able to say so.
 *
 * Every measure describes the HERD of the batch. A bull of a running service is physically in it
 * (caravans_count) but measures nothing: he neither bends the average nor opens a step in the curve.
 */
class BatchWeightService
{
    public function __construct(
        private readonly IBatchRepository $batchRepository,
        private readonly ICaravanRepository $caravanRepository
    ) {
    }

    /**
     * Recalculates the aggregate of a batch and records a point in its series.
     *
     * The caller states WHY, because it is the only one that knows: a weighing measures
     * the same set of animals again, while a movement changes the set itself. Blending
     * both into a single CONTROL makes a compositional jump indistinguishable from a
     * loss of weight.
     *
     * A point produced by a weighing names its purpose, so the curve can tell a growth control
     * from a pregnancy-check or entry weighing. Movements and snapshots carry none.
     */
    public function recalculateBatchWeight(
        int $batchId,
        BatchWeightCause $cause = BatchWeightCause::CONTROL,
        ?\DateTimeInterface $date = null,
        ?WeighingTypeCode $weighingType = null,
        ?int $weighingOrderId = null
    ): void {
        $this->recalculate($batchId, $cause, $date, $weighingType, $weighingOrderId, false);
    }

    /**
     * A bull joined or left the service running on this batch. He is not part of its herd, so the
     * batch is updated (it holds one more or one less) but its curve only gets a point if the herd
     * did change: a bull that already lived here, or one that stays after the order stopped running.
     */
    public function recalculateServicedBatch(int $batchId, BatchWeightCause $cause): void
    {
        $this->recalculate($batchId, $cause, null, null, null, true);
    }

    private function recalculate(
        int $batchId,
        BatchWeightCause $cause,
        ?\DateTimeInterface $date,
        ?WeighingTypeCode $weighingType,
        ?int $weighingOrderId,
        bool $pointOnlyIfHerdChanged
    ): void {
        $batch = $this->batchRepository->findById($batchId);
        if (!$batch) {
            return;
        }

        $herdCount = $this->caravanRepository->countResidentByBatch($batchId);
        $weighedCount = $this->caravanRepository->countWeighedByBatch($batchId);

        // Mass is additive and survives a split: the kilos missing from the source are,
        // exactly, the kilos found in the destination. The average is a ratio that is
        // not additive and can move in either direction without any animal changing.
        $totalWeight = $weighedCount > 0
            ? ($this->caravanRepository->getTotalWeightByBatch($batchId) ?? 0.0)
            : 0.0;

        // The average of an empty set is undefined. Writing 0.0 would state that the
        // animals weigh nothing, which is how an emptied batch ends up with a curve
        // that plunges to the floor.
        $newAvg = $weighedCount > 0 ? $totalWeight / $weighedCount : null;

        $weightsAsOf = $weighedCount > 0
            ? $this->caravanRepository->getLatestWeighingDateByBatch($batchId)
            : null;

        $batch->setCurrentWeight($newAvg);
        $batch->setTotalWeight($totalWeight);
        $batch->setCaravansCount($this->caravanRepository->countByBatch($batchId));
        $batch->setWeighedCount($weighedCount);
        $batch->setMinWeight($this->caravanRepository->getMinWeightByBatch($batchId));
        $batch->setMaxWeight($this->caravanRepository->getMaxWeightByBatch($batchId));

        $effectiveDate = $date ?? new \DateTimeImmutable();

        if (!$pointOnlyIfHerdChanged || $this->herdChanged($batchId, $herdCount, $weighedCount, $totalWeight)) {
            $this->batchRepository->addWeight(
                $batchId,
                $newAvg,
                $cause->value,
                $effectiveDate,
                $batch->getActivityId(),
                $totalWeight,
                $herdCount,
                $weighedCount,
                $weightsAsOf,
                $cause === BatchWeightCause::CONTROL ? $weighingType : null,
                $cause === BatchWeightCause::CONTROL ? $weighingOrderId : null
            );
        }

        // Last: the series counts the herd, the batch counts everything it physically holds.
        $this->batchRepository->save($batch);
    }

    private function herdChanged(int $batchId, int $herdCount, int $weighedCount, float $totalWeight): bool
    {
        $last = $this->batchRepository->findLatestWeight($batchId);

        return $last === null
            || $last->getCaravansCount() !== $herdCount
            || $last->getWeighedCount() !== $weighedCount
            || abs(($last->getTotalWeight() ?? 0.0) - $totalWeight) >= 0.01;
    }

    /**
     * Records the state of the batch as it still stands, right before animals move.
     *
     * This closing point anchors the OLD composition on the date of the movement, so it
     * is written even when its values repeat the previous row: without it, the step
     * would be drawn spread over every day since the last weighing, which reads as a
     * gradual loss of weight instead of an instantaneous change of the set. The guard
     * only suppresses a duplicate within the very same day.
     */
    public function snapshotBeforeMovement(int $batchId, ?\DateTimeInterface $date = null): void
    {
        $caravansCount = $this->caravanRepository->countResidentByBatch($batchId);

        // A batch that holds no animals has no composition to close.
        if ($caravansCount === 0) {
            return;
        }

        $weighedCount = $this->caravanRepository->countWeighedByBatch($batchId);
        $totalWeight = $weighedCount > 0
            ? ($this->caravanRepository->getTotalWeightByBatch($batchId) ?? 0.0)
            : 0.0;

        $last = $this->batchRepository->findLatestWeight($batchId);
        $effectiveDate = $date ?? new \DateTimeImmutable();

        if ($last !== null
            && $last->getWeighingDate()->format('Y-m-d') === $effectiveDate->format('Y-m-d')
            && $last->getCaravansCount() === $caravansCount
            && $last->getWeighedCount() === $weighedCount
            && abs(($last->getTotalWeight() ?? 0.0) - $totalWeight) < 0.01
        ) {
            return;
        }

        $this->recalculateBatchWeight($batchId, BatchWeightCause::CONTROL, $effectiveDate);
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
