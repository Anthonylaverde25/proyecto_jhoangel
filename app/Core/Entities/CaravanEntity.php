<?php

declare(strict_types=1);

namespace App\Core\Entities;

use App\Core\Enums\AnimalCategory;
use App\Core\Enums\AnimalSex;
use App\Core\Enums\GestationResult;
use App\Core\Enums\GestationStage;
use App\Core\Exceptions\DomainException;
use App\Core\ValueObjects\CaravanNumber;
use App\Core\ValueObjects\FemaleReproductiveDetails;

final class CaravanEntity
{
    public function __construct(
        private readonly ?int $id,
        private CaravanNumber $identification,
        private ?AnimalCategory $category,
        private int $teeth,
        private ?float $entryWeight = null,
        private ?float $exitWeight = null,
        private ?string $breed = null,
        private ?int $breedId = null,
        private AnimalSex $sex,
        private ?\DateTimeInterface $entryDate = null,
        private ?\DateTimeInterface $createdAt = null,
        private ?int $batchId = null,
        private ?int $companyId = null,
        private ?string $batchName = null,
        private ?float $currentWeight = null,
        private ?FemaleReproductiveDetails $reproductiveDetails = null,
        /** @var GestationEntity[] */
        private array $gestations = []
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

    public function getBatchId(): ?int
    {
        return $this->batchId;
    }

    public function getCompanyId(): ?int
    {
        return $this->companyId;
    }

    public function getBatchName(): ?string
    {
        return $this->batchName;
    }

    public function getCurrentWeight(): ?float
    {
        return $this->currentWeight;
    }

    public function getIdentification(): CaravanNumber
    {
        return $this->identification;
    }

    public function getCategory(): ?AnimalCategory
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

    public function getBreed(): ?string
    {
        return $this->breed;
    }

    public function getBreedId(): ?int
    {
        return $this->breedId;
    }

    public function getSex(): AnimalSex
    {
        return $this->sex;
    }

    public function getEntryDate(): ?\DateTimeInterface
    {
        return $this->entryDate;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getReproductiveDetails(): ?FemaleReproductiveDetails
    {
        return $this->reproductiveDetails;
    }

    public function recordFemaleDetails(FemaleReproductiveDetails $details): void
    {
        if ($this->sex !== AnimalSex::FEMALE) {
            throw new DomainException("Solo las hembras pueden tener detalles reproductivos.");
        }
        
        $this->reproductiveDetails = $details;
    }

    /**
     * @return GestationEntity[]
     */
    public function getGestations(): array
    {
        return $this->gestations;
    }

    public function hasActiveGestation(): bool
    {
        foreach ($this->gestations as $gestation) {
            if ($gestation->isCurrent()) {
                return true;
            }
        }
        return false;
    }

    public function getActiveGestation(): ?GestationEntity
    {
        foreach ($this->gestations as $gestation) {
            if ($gestation->isCurrent()) {
                return $gestation;
            }
        }
        return null;
    }

    public function startNewGestation(?string $startDate, GestationStage $gestationStage, float $gestationMonths): void
    {
        if ($this->sex !== AnimalSex::FEMALE) {
            throw new DomainException("Solo las hembras pueden tener procesos de gestación.");
        }

        // Close any currently active gestation (in case of data inconsistency or override)
        foreach ($this->gestations as $gestation) {
            if ($gestation->isCurrent()) {
                $gestation->closeGestation(GestationResult::FAILED, date('Y-m-d'), 'Cerrado automáticamente por nueva gestación.');
            }
        }

        $this->gestations[] = new GestationEntity(
            id: null,
            startDate: $startDate,
            estimatedDueDate: null,
            isCurrent: true,
            result: null,
            endDate: null,
            notes: 'Gestación iniciada automáticamente.',
            gestationStage: $gestationStage,
            gestationMonths: $gestationMonths
        );
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
     * Actualiza los detalles del animal permitidos según el patrón Upsert.
     * La identificación y la fecha de entrada son inmutables.
     */
    public function updateDetails(
        ?AnimalCategory $category,
        int $teeth,
        ?float $entryWeight,
        ?float $exitWeight,
        ?string $breed,
        ?AnimalSex $sex,
        ?\DateTimeInterface $entryDate = null,
        ?int $batchId = null,
        ?int $breedId = null
    ): void {
        $this->validateTeeth($teeth);
        
        $this->category = $category;
        $this->teeth = $teeth;
        $this->entryWeight = $entryWeight;
        $this->exitWeight = $exitWeight;
        $this->breed = $breed;
        
        if ($breedId !== null) {
            $this->breedId = $breedId;
        }
        
        if ($sex !== null) {
            $this->sex = $sex;
        }

        if ($entryDate !== null) {
            $this->entryDate = $entryDate;
        }

        if ($batchId !== null) {
            $this->batchId = $batchId;
        }
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
