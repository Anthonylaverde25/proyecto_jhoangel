<?php

declare(strict_types=1);

namespace App\Core\Entities;

class AnimalCategoryEntity
{
    /**
     * @param AnimalSubcategoryEntity[] $subcategories
     */
    public function __construct(
        private readonly ?int $id,
        private readonly string $code,
        private readonly string $name,
        private readonly string $sex = 'BOTH',
        private readonly ?int $minAgeMonths = null,
        private readonly ?int $maxAgeMonths = null,
        private readonly ?float $minWeightKg = null,
        private readonly ?float $maxWeightKg = null,
        private readonly bool $isReproductive = false,
        private readonly ?string $description = null,
        private array $subcategories = []
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getSex(): string
    {
        return $this->sex;
    }

    public function getMinAgeMonths(): ?int
    {
        return $this->minAgeMonths;
    }

    public function getMaxAgeMonths(): ?int
    {
        return $this->maxAgeMonths;
    }

    public function getMinWeightKg(): ?float
    {
        return $this->minWeightKg;
    }

    public function getMaxWeightKg(): ?float
    {
        return $this->maxWeightKg;
    }

    public function isReproductive(): bool
    {
        return $this->isReproductive;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    /**
     * @return AnimalSubcategoryEntity[]
     */
    public function getSubcategories(): array
    {
        return $this->subcategories;
    }

    public function addSubcategory(AnimalSubcategoryEntity $subcategory): void
    {
        $this->subcategories[] = $subcategory;
    }
}
