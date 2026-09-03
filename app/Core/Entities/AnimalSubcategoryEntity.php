<?php

declare(strict_types=1);

namespace App\Core\Entities;

class AnimalSubcategoryEntity
{
    public function __construct(
        private readonly ?int $id,
        private readonly int $categoryId,
        private readonly string $code,
        private readonly string $name,
        private readonly ?float $targetWeightMin = null,
        private readonly ?float $targetWeightMax = null,
        private readonly ?string $description = null
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCategoryId(): int
    {
        return $this->categoryId;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getTargetWeightMin(): ?float
    {
        return $this->targetWeightMin;
    }

    public function getTargetWeightMax(): ?float
    {
        return $this->targetWeightMax;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }
}
