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
        private readonly float $weight,
        private readonly string $type,
        private readonly \DateTimeInterface $weighingDate
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

    public function getWeight(): float
    {
        return $this->weight;
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
