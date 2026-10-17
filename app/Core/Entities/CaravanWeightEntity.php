<?php

declare(strict_types=1);

namespace App\Core\Entities;

use App\Core\Enums\ShrunkCondition;
use App\Core\Enums\WeighingMethod;
use App\Core\Enums\WeighingSource;
use App\Core\Enums\WeighingTypeCode;

final class CaravanWeightEntity
{
    public function __construct(
        private readonly ?int $id,
        private readonly int $caravanId,
        private readonly float $weight,
        private readonly bool $current,
        private readonly \DateTimeInterface $weighingDate,
        private readonly ?string $notes = null,
        private readonly ?\DateTimeInterface $createdAt = null,
        private readonly WeighingTypeCode $type = WeighingTypeCode::CONTROL,
        private readonly WeighingMethod $method = WeighingMethod::INDIVIDUAL,
        private readonly ShrunkCondition $shrunkCondition = ShrunkCondition::FULL_WEIGHT,
        private readonly ?float $shrinkPct = null,
        private readonly ?string $weighingTime = null,
        private readonly WeighingSource $source = WeighingSource::MANUAL,
        private readonly ?int $weighingOrderId = null,
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

    public function getType(): WeighingTypeCode
    {
        return $this->type;
    }

    public function getMethod(): WeighingMethod
    {
        return $this->method;
    }

    public function getShrunkCondition(): ShrunkCondition
    {
        return $this->shrunkCondition;
    }

    public function getShrinkPct(): ?float
    {
        return $this->shrinkPct;
    }

    public function getWeighingTime(): ?string
    {
        return $this->weighingTime;
    }

    public function getSource(): WeighingSource
    {
        return $this->source;
    }

    public function getWeighingOrderId(): ?int
    {
        return $this->weighingOrderId;
    }
}
