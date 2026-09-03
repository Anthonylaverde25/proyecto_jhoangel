<?php

declare(strict_types=1);

namespace App\Core\Entities;

final class ServiceBatchDetailEntity
{
    public function __construct(
        private readonly ?int $id,
        private readonly int $batchId,
        private readonly int $femaleCategoryId,
        private readonly int $maleCategoryId,
        private readonly ?int $femaleSubcategoryId = null,
        private readonly ?string $femaleCategoryName = null,
        private readonly ?string $femaleCategoryCode = null,
        private readonly ?string $femaleSubcategoryName = null,
        private readonly ?string $femaleSubcategoryCode = null,
        private readonly ?string $maleCategoryName = null,
        private readonly ?string $maleCategoryCode = null,
        private readonly ?float $targetBullRatio = 3.00,
        private readonly ?string $plannedStartDate = null,
        private readonly ?string $plannedEndDate = null,
        private readonly ?string $notes = null
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

    public function getFemaleCategoryId(): int
    {
        return $this->femaleCategoryId;
    }

    public function getFemaleSubcategoryId(): ?int
    {
        return $this->femaleSubcategoryId;
    }

    public function getMaleCategoryId(): int
    {
        return $this->maleCategoryId;
    }

    public function getFemaleCategoryName(): ?string
    {
        return $this->femaleCategoryName;
    }

    public function getFemaleCategoryCode(): ?string
    {
        return $this->femaleCategoryCode;
    }

    public function getFemaleSubcategoryName(): ?string
    {
        return $this->femaleSubcategoryName;
    }

    public function getFemaleSubcategoryCode(): ?string
    {
        return $this->femaleSubcategoryCode;
    }

    public function getMaleCategoryName(): ?string
    {
        return $this->maleCategoryName;
    }

    public function getMaleCategoryCode(): ?string
    {
        return $this->maleCategoryCode;
    }

    public function getTargetBullRatio(): ?float
    {
        return $this->targetBullRatio;
    }

    public function getPlannedStartDate(): ?string
    {
        return $this->plannedStartDate;
    }

    public function getPlannedEndDate(): ?string
    {
        return $this->plannedEndDate;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }
}
