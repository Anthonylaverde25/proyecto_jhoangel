<?php

declare(strict_types=1);

namespace App\Core\Entities;

final class CaravanWeightEntity
{
    public function __construct(
        private readonly ?int $id,
        private readonly int $caravanId,
        private readonly float $weight,
        private readonly bool $current,
        private readonly \DateTimeInterface $weighingDate,
        private readonly ?string $notes = null,
        private readonly ?\DateTimeInterface $createdAt = null
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCaravanId(): int
    {
        return $this->caravanId;
    }

    public function getWeight(): float
    {
        return $this->weight;
    }

    public function isCurrent(): bool
    {
        return $this->current;
    }

    public function getWeighingDate(): \DateTimeInterface
    {
        return $this->weighingDate;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }
}
