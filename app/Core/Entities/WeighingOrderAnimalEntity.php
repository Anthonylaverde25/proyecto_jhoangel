<?php

declare(strict_types=1);

namespace App\Core\Entities;

/**
 * One animal committed by a weighing order. The kilos are never here: they live in
 * caravan_weights, and the line only points at the weight that fulfilled it.
 */
final class WeighingOrderAnimalEntity
{
    public const STATUS_PENDING = 'PENDING';
    public const STATUS_WEIGHED = 'WEIGHED';
    public const STATUS_SKIPPED = 'SKIPPED';

    public function __construct(
        private readonly ?int $id,
        private readonly int $caravanId,
        private readonly ?int $sourceBatchId,
        private string $status = self::STATUS_PENDING,
        private ?int $caravanWeightId = null,
        private ?\DateTimeInterface $weighedAt = null,
        private readonly ?string $identification = null,
    ) {
    }

    public function markWeighed(int $caravanWeightId, \DateTimeInterface $at): void
    {
        $this->status = self::STATUS_WEIGHED;
        $this->caravanWeightId = $caravanWeightId;
        $this->weighedAt = $at;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCaravanId(): int
    {
        return $this->caravanId;
    }

    public function getSourceBatchId(): ?int
    {
        return $this->sourceBatchId;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getCaravanWeightId(): ?int
    {
        return $this->caravanWeightId;
    }

    public function getWeighedAt(): ?\DateTimeInterface
    {
        return $this->weighedAt;
    }

    public function getIdentification(): ?string
    {
        return $this->identification;
    }
}
