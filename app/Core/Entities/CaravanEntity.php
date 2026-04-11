<?php

declare(strict_types=1);

namespace App\Core\Entities;

use App\Core\Enums\AnimalCategory;
use App\Core\Exceptions\DomainException;
use App\Core\ValueObjects\CaravanNumber;

final class CaravanEntity
{
    public function __construct(
        private readonly ?int $id,
        private CaravanNumber $identification,
        private AnimalCategory $category,
        private int $teeth,
        private ?float $entryWeight = null,
        private ?float $exitWeight = null,
    ) {
        $this->validateTeeth($teeth);
    }

    /**
     * @throws DomainException
     */
    private function validateTeeth(int $teeth): void
    {
        if ($teeth < 0 || $teeth > 99) {
            throw new DomainException("La dentición debe estar en el rango de 00 a 99.");
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getIdentification(): CaravanNumber
    {
        return $this->identification;
    }

    public function getCategory(): AnimalCategory
    {
        return $this->category;
    }

    public function getTeeth(): int
    {
        return $this->teeth;
    }

    public function getEntryWeight(): ?float
    {
        return $this->entryWeight;
    }

    public function getExitWeight(): ?float
    {
        return $this->exitWeight;
    }

    public function updateCategory(AnimalCategory $category): void
    {
        $this->category = $category;
    }

    /**
     * @throws DomainException
     */
    public function updateTeeth(int $teeth): void
    {
        $this->validateTeeth($teeth);
        $this->teeth = $teeth;
    }

    public function recordExitWeight(float $weight): void
    {
        $this->exitWeight = $weight;
    }

    /**
     * Calcula la ganancia de peso total.
     */
    public function calculateWeightGain(): ?float
    {
        if ($this->entryWeight === null || $this->exitWeight === null) {
            return null;
        }

        return $this->exitWeight - $this->entryWeight;
    }
}
