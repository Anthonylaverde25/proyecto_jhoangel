<?php

declare(strict_types=1);

namespace App\Core\Entities;

use App\Core\Enums\AnimalCategory;
use App\Core\Enums\AnimalSex;
use App\Core\Enums\GestationStage;
use App\Core\Enums\PhysiologicalState;
use App\Core\Exceptions\DomainException;
use App\Core\ValueObjects\CaravanNumber;
use App\Core\ValueObjects\CaravanProvenance;
use App\Core\ValueObjects\FemaleReproductiveDetails;

final class CaravanEntity
{
    public function __construct(
        private readonly ?int $id,
        private CaravanNumber $identification,
        private int $teeth,
        private ?float $entryWeight = null,
        private ?float $exitWeight = null,
        private ?int $breedId = null,
        private ?string $breedName = null,
        private ?int $colorId = null,
        private ?string $colorName = null,
        private AnimalSex $sex = AnimalSex::FEMALE,
        private ?\DateTimeInterface $entryDate = null,
        private ?\DateTimeInterface $createdAt = null,
        private ?int $batchId = null,
        private ?int $companyId = null,
        private ?string $batchName = null,
        private ?float $currentWeight = null,
        private ?FemaleReproductiveDetails $reproductiveDetails = null,
        /** @var GestationEntity[] */
        private array $gestations = [],
        private ?LineageEntity $lineage = null,
        private string $renspa = 'NO_DEFINIDO',
        private ?int $providerId = null,
        private ?string $providerName = null,
        private ?CaravanProvenance $provenance = null,
        private ?int $categoryId = null,
        private ?string $categoryCode = null,
        private ?string $categoryName = null,
        private ?int $subcategoryId = null,
        private ?string $subcategoryCode = null,
        private ?string $subcategoryName = null,
        private bool $isInService = false,
        private ?string $farmName = null
    ) {
        $this->validateTeeth($teeth);
    }

    /**
     * @throws DomainException
     */
    private function validateTeeth(int $teeth): void
    {
        if ($teeth < 0 || $teeth > 99) {
            throw new DomainException("La dentición debe estar en el rango de 0 a 99.");
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

    public function getFarmName(): ?string
    {
        return $this->farmName;
    }

    public function setFarmName(?string $farmName): void
    {
        $this->farmName = $farmName;
    }

    public function getCurrentWeight(): ?float
    {
        return $this->currentWeight;
    }

    public function getIdentification(): CaravanNumber
    {
        return $this->identification;
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
        return $this->breedName;
    }

    public function getBreedName(): ?string
    {
        return $this->breedName;
    }

    public function setBreedName(?string $breedName): void
    {
        $this->breedName = $breedName;
    }

    public function getBreedId(): ?int
    {
        return $this->breedId;
    }

    public function setBreedId(?int $breedId): void
    {
        $this->breedId = $breedId;
    }

    public function getColorId(): ?int
    {
        return $this->colorId;
    }

    public function setColorId(?int $colorId): void
    {
        $this->colorId = $colorId;
    }

    public function getColorName(): ?string
    {
        return $this->colorName;
    }

    public function setColorName(?string $colorName): void
    {
        $this->colorName = $colorName;
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

    public function getLineage(): ?LineageEntity
    {
        return $this->lineage;
    }

    public function recordLineage(LineageEntity $lineage): void
    {
        $this->lineage = $lineage;
    }

    public function isNursing(): bool
    {
        return $this->lineage?->isNursing() ?? false;
    }

    public function startNewGestation(
        ?string $startDate,
        GestationStage $gestationStage,
        float $gestationMonths,
        ?int $serviceOrderId = null
    ): void {
        if ($this->sex !== AnimalSex::FEMALE) {
            throw new DomainException("Solo las hembras pueden tener procesos de gestación.");
        }

        // Close any currently active gestation (in case of data inconsistency or override)
        foreach ($this->gestations as $gestation) {
            if ($gestation->isCurrent()) {
                $gestation->closeGestation(false, date('Y-m-d'), 'Cerrado automáticamente por nueva gestación.');
            }
        }

        $this->gestations[] = new GestationEntity(
            id: null,
            startDate: $startDate,
            estimatedDueDate: null,
            isCurrent: true,
            success: null,
            lossReasonId: null,
            lossNotes: null,
            endDate: null,
            notes: 'Gestación iniciada automáticamente.',
            gestationStage: $gestationStage,
            gestationMonths: $gestationMonths,
            sires: [],
            serviceOrderId: $serviceOrderId
        );
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
        int $teeth,
        ?float $entryWeight,
        ?float $exitWeight,
        ?AnimalSex $sex,
        ?\DateTimeInterface $entryDate = null,
        ?int $batchId = null,
        ?int $breedId = null,
        ?int $colorId = null,
        ?int $categoryId = null,
        ?int $subcategoryId = null
    ): void {
        $this->validateTeeth($teeth);
        
        $this->teeth = $teeth;
        $this->entryWeight = $entryWeight;
        $this->exitWeight = $exitWeight;
        
        if ($breedId !== null) {
            $this->breedId = $breedId;
        }

        if ($colorId !== null) {
            $this->colorId = $colorId;
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

        if ($categoryId !== null) {
            $this->categoryId = $categoryId;
        }

        if ($subcategoryId !== null) {
            $this->subcategoryId = $subcategoryId;
        }
    }

    /**
     * Mueve el animal a un nuevo lote.
     */
    public function moveToBatch(int $batchId): void
    {
        $this->batchId = $batchId;
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

    public function setBatchId(?int $batchId): void
    {
        $this->batchId = $batchId;
    }

    public function getRenspa(): string
    {
        return $this->renspa;
    }

    public function setRenspa(string $renspa): void
    {
        $this->renspa = $renspa;
    }

    public function getProviderId(): ?int
    {
        return $this->providerId;
    }

    public function setProviderId(?int $providerId): void
    {
        $this->providerId = $providerId;
    }

    public function getProviderName(): ?string
    {
        return $this->providerName;
    }

    public function setProviderName(?string $providerName): void
    {
        $this->providerName = $providerName;
    }

    public function getProvenance(): ?CaravanProvenance
    {
        return $this->provenance;
    }

    public function setProvenance(?CaravanProvenance $provenance): void
    {
        $this->provenance = $provenance;
    }

    public function getCategoryId(): ?int
    {
        return $this->categoryId;
    }

    public function setCategoryId(?int $categoryId): void
    {
        $this->categoryId = $categoryId;
    }

    public function getCategoryCode(): ?string
    {
        return $this->categoryCode;
    }

    public function setCategoryCode(?string $categoryCode): void
    {
        $this->categoryCode = $categoryCode;
    }

    public function getCategoryName(): ?string
    {
        return $this->categoryName;
    }

    public function setCategoryName(?string $categoryName): void
    {
        $this->categoryName = $categoryName;
    }

    public function getSubcategoryId(): ?int
    {
        return $this->subcategoryId;
    }

    public function setSubcategoryId(?int $subcategoryId): void
    {
        $this->subcategoryId = $subcategoryId;
    }

    public function getSubcategoryCode(): ?string
    {
        return $this->subcategoryCode;
    }

    public function setSubcategoryCode(?string $subcategoryCode): void
    {
        $this->subcategoryCode = $subcategoryCode;
    }

    public function getSubcategoryName(): ?string
    {
        return $this->subcategoryName;
    }

    public function setSubcategoryName(?string $subcategoryName): void
    {
        $this->subcategoryName = $subcategoryName;
    }

    public function isInService(): bool
    {
        return $this->isInService;
    }

    public function setIsInService(bool $isInService): void
    {
        $this->isInService = $isInService;
    }

    /**
     * Compute the dynamic physiological state of the animal (Pure DDD).
     */
    public function getPhysiologicalState(): PhysiologicalState
    {
        if ($this->sex !== AnimalSex::FEMALE) {
            return PhysiologicalState::UNKNOWN;
        }

        if ($this->isInService) {
            return PhysiologicalState::IN_SERVICE;
        }

        $hasActiveGestation = $this->hasActiveGestation();
        $isNursing = $this->isNursing();

        if ($hasActiveGestation && $isNursing) {
            return PhysiologicalState::PREGNANT_LACTATING;
        }

        if ($hasActiveGestation && !$isNursing) {
            return PhysiologicalState::PREGNANT_DRY;
        }

        if (!$hasActiveGestation && $isNursing) {
            return PhysiologicalState::EMPTY_LACTATING;
        }

        // Female not pregnant and not nursing
        return PhysiologicalState::EMPTY_DRY;
    }

    public function isPregnant(): ?bool
    {
        if ($this->sex !== AnimalSex::FEMALE) {
            return null;
        }

        return $this->hasActiveGestation();
    }

    public function getGestationMonths(): ?float
    {
        return $this->getActiveGestation()?->getGestationMonths();
    }

    public function getGestationStage(): ?GestationStage
    {
        return $this->getActiveGestation()?->getGestationStage();
    }
}
