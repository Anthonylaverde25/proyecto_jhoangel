<?php

declare(strict_types=1);

namespace App\Core\Entities;

final class BatchWeightEntity
{
    public function __construct(
        private readonly ?int $id,
        private readonly int $batchId,
        private readonly ?int $activityId,
        private readonly ?string $activityName,
        private readonly ?float $weight,
        private readonly string $type,
        private readonly \DateTimeInterface $weighingDate,
        private readonly ?float $totalWeight = null,
        private readonly ?int $caravansCount = null,
        private readonly ?int $weighedCount = null,
        private readonly ?\DateTimeInterface $weightsAsOf = null
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBatchId(): int
    {
        return $this->batchId;
    }

    public function getActivityId(): ?int
    {
        return $this->activityId;
    }

    public function getActivityName(): ?string
    {
        return $this->activityName;
    }

    /** Average kg per head. Null when the set is empty: an average of nothing is undefined. */
    public function getWeight(): ?float
    {
        return $this->weight;
    }

    /** Measured mass, over the animals with a current weight. Additive and conserved on a split. */
    public function getTotalWeight(): ?float
    {
        return $this->totalWeight;
    }

    /** Head in the batch. Null on rows written before composition was recorded. */
    public function getCaravansCount(): ?int
    {
        return $this->caravansCount;
    }

    /** Head the average was actually computed over; may be smaller than the batch. */
    public function getWeighedCount(): ?int
    {
        return $this->weighedCount;
    }

    /** Newest individual weighing behind this point. */
    public function getWeightsAsOf(): ?\DateTimeInterface
    {
        return $this->weightsAsOf;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getWeighingDate(): \DateTimeInterface
    {
        return $this->weighingDate;
    }
}
