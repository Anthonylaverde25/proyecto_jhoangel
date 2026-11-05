<?php

declare(strict_types=1);

namespace App\Core\Entities;

use App\Core\Enums\CategoryChangeCause;
use App\Core\Enums\CategoryChangeSource;

final class CategoryChangeEntity
{
    public function __construct(
        private readonly ?int $id,
        private readonly int $companyId,
        private readonly int $caravanId,
        private readonly ?int $fromCategoryId,
        private readonly ?string $fromCategoryCode,
        private readonly ?string $fromCategoryName,
        private readonly ?int $fromSubcategoryId,
        private readonly ?string $fromSubcategoryName,
        private readonly ?int $toCategoryId,
        private readonly ?string $toCategoryCode,
        private readonly ?string $toCategoryName,
        private readonly ?int $toSubcategoryId,
        private readonly ?string $toSubcategoryName,
        private readonly CategoryChangeCause $cause,
        private readonly string $occurredOn,
        private readonly ?CategoryChangeSource $sourceType,
        private readonly ?int $sourceId,
        private readonly ?int $registeredByUserId,
        private readonly ?string $registeredByUserName = null,
        private readonly ?string $createdAt = null
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCompanyId(): int
    {
        return $this->companyId;
    }

    public function getCaravanId(): int
    {
        return $this->caravanId;
    }

    public function getFromCategoryId(): ?int
    {
        return $this->fromCategoryId;
    }

    public function getFromCategoryCode(): ?string
    {
        return $this->fromCategoryCode;
    }

    public function getFromCategoryName(): ?string
    {
        return $this->fromCategoryName;
    }

    public function getFromSubcategoryId(): ?int
    {
        return $this->fromSubcategoryId;
    }

    public function getFromSubcategoryName(): ?string
    {
        return $this->fromSubcategoryName;
    }

    public function getToCategoryId(): ?int
    {
        return $this->toCategoryId;
    }

    public function getToCategoryCode(): ?string
    {
        return $this->toCategoryCode;
    }

    public function getToCategoryName(): ?string
    {
        return $this->toCategoryName;
    }

    public function getToSubcategoryId(): ?int
    {
        return $this->toSubcategoryId;
    }

    public function getToSubcategoryName(): ?string
    {
        return $this->toSubcategoryName;
    }

    public function getCause(): CategoryChangeCause
    {
        return $this->cause;
    }

    public function getOccurredOn(): string
    {
        return $this->occurredOn;
    }

    public function getSourceType(): ?CategoryChangeSource
    {
        return $this->sourceType;
    }

    public function getSourceId(): ?int
    {
        return $this->sourceId;
    }

    public function getRegisteredByUserId(): ?int
    {
        return $this->registeredByUserId;
    }

    public function getRegisteredByUserName(): ?string
    {
        return $this->registeredByUserName;
    }

    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }
}

