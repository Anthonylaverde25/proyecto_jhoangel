<?php

declare(strict_types=1);

namespace App\Core\Entities;

use App\Core\Enums\AnimalCategory;
use App\Core\Enums\AnimalSex;
use App\Core\Enums\GestationStage;
use App\Core\Enums\PhysiologicalState;
use App\Core\Exceptions\AnimalDeathException;
use App\Core\Exceptions\DomainException;
use App\Core\Exceptions\UntaggedAnimalException;
use App\Core\ValueObjects\AnimalLabel;
use App\Core\ValueObjects\CaravanNumber;
use App\Core\ValueObjects\DeathRecord;
use App\Core\ValueObjects\CaravanProvenance;
use App\Core\ValueObjects\FemaleReproductiveDetails;

final class CaravanEntity
{
    public function __construct(
        private readonly ?int $id,
        /** Null while a calf born in the system waits for its tag. */
        private ?CaravanNumber $identification,
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
        private ?string $farmName = null,
        private ?string $taggedAt = null,
        private ?DeathRecord $death = null,
        private ?int $nursingCalvesCount = null
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

    /**
     * The owner's breeding decision. Null when the entity was built without it (it defaults
     * to eligible in the database).
     */
    private ?\App\Core\ValueObjects\BreedingEligibility $breedingEligibility = null;

    public function getBreedingEligibility(): ?\App\Core\ValueObjects\BreedingEligibility
    {
        return $this->breedingEligibility;
    }

    public function setBreedingEligibility(?\App\Core\ValueObjects\BreedingEligibility $breedingEligibility): void
    {
        $this->breedingEligibility = $breedingEligibility;
    }

    /**
     * The catalog's word on whether the category breeds. Null when the entity was built
     * without its category, or the caravan has none.
     */
    private ?bool $isReproductiveCategory = null;

    public function isReproductiveCategory(): ?bool
    {
        return $this->isReproductiveCategory;
    }

    public function setReproductiveCategory(?bool $isReproductiveCategory): void
    {
        $this->isReproductiveCategory = $isReproductiveCategory;
    }

    public function getCurrentWeight(): ?float
    {
        return $this->currentWeight;
    }

    /**
     * The tag, for the flows that work by tag.
     *
     * @throws UntaggedAnimalException a calf that has not been tagged yet
     */
    public function getIdentification(): CaravanNumber
    {
        return $this->identification ?? throw UntaggedAnimalException::for($this);
    }

    /**
     * The tag, or null for a calf still waiting for it: for showing or mapping the animal.
     */
    public function findIdentification(): ?CaravanNumber
    {
        return $this->identification;
    }

    public function isTagged(): bool
    {
        return $this->identification !== null;
    }

    public function getTaggedAt(): ?string
    {
        return $this->taggedAt;
    }

    /**
     * How the animal is named on screen: its tag, or its mother's while it has none.
     */
    public function label(): string
    {
        return AnimalLabel::of($this->identification?->getValue(), $this->lineage?->getMotherIdentification(), $this->sex);
    }

    public function getDeath(): ?DeathRecord
    {
        return $this->death;
    }

    public function isDead(): bool
    {
        return $this->death !== null;
    }

    /**
     * Registers the death: a calf at foot stops nursing, a pregnant female loses her gestation
     * (charged to her death). Moving it to a death batch is the caller's job.
     *
     * @param int|null $motherDeathLossReasonId the MOTHER_DEATH loss reason, for a pregnant female
     *
     * @throws AnimalDeathException dead already, or a date before its birth or after today
     */
    public function registerDeath(string $date, int $causeId, ?string $observations, ?int $motherDeathLossReasonId): void
    {
        if ($this->death !== null) {
            throw AnimalDeathException::alreadyDead($this->label(), $this->death->date);
        }

        $birthDate = $this->lineage?->getBirthDate();
        if ($birthDate !== null && $date < $birthDate) {
            throw AnimalDeathException::beforeBirth($this->label(), $birthDate);
        }

        if ($date > date('Y-m-d')) {
            throw AnimalDeathException::future($this->label());
        }

        if ($this->lineage?->isNursing()) {
            $this->lineage->endNursingByDeath($date);
        }

        $this->getActiveGestation()?->closeGestation(
            success: false,
            endDate: $date,
            lossReasonId: $motherDeathLossReasonId,
            lossNotes: 'La hembra murió preñada.'
        );

        $this->death = new DeathRecord($date, $causeId, null, $observations);
    }

    /**
     * Puts the tag on a calf that was born without one.
     *
     * @throws UntaggedAnimalException the animal already has a tag
     * @throws DomainException the date falls before the birth or after today
     */
    public function assignTag(CaravanNumber $tag, string $taggedAt): void
    {
        if ($this->identification !== null) {
            throw UntaggedAnimalException::alreadyTagged($this);
        }

        $birthDate = $this->lineage?->getBirthDate();

        if ($birthDate !== null && $taggedAt < $birthDate) {
            throw new DomainException("La fecha de caravaneo de la " . lcfirst($this->label()) . " es anterior a su nacimiento ({$birthDate}).");
        }

        if ($taggedAt > date('Y-m-d')) {
            throw new DomainException("La fecha de caravaneo de la " . lcfirst($this->label()) . " no puede ser futura.");
        }

        $this->identification = $tag;
        $this->taggedAt = $taggedAt;
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

    /**
     * Gestations closed by a calf born dead: the mother's record. A calf that died at foot is not
     * counted — that gestation closed successful.
     *
     * @return GestationEntity[] newest first
     */
    public function getStillbirths(): array
    {
        $stillbirths = array_values(array_filter($this->gestations, fn (GestationEntity $g) => $g->isStillbirth()));
        usort($stillbirths, fn (GestationEntity $a, GestationEntity $b) => strcmp((string) $b->getEndDate(), (string) $a->getEndDate()));

        return $stillbirths;
    }

    public function getLineage(): ?LineageEntity
    {
        return $this->lineage;
    }

    public function recordLineage(LineageEntity $lineage): void
    {
        $this->lineage = $lineage;
    }

    /**
     * Whether this animal is itself a calf at foot of its mother.
     */
    public function isNursing(): bool
    {
        return $this->lineage?->isNursing() ?? false;
    }

    /**
     * How many of her own calves are still at foot; null when it was not read.
     */
    public function getNursingCalvesCount(): ?int
    {
        return $this->nursingCalvesCount;
    }

    public function setNursingCalvesCount(?int $count): void
    {
        $this->nursingCalvesCount = $count;
    }

    /**
     * Whether she has a calf of her own at foot: what makes a female lactating.
     */
    public function hasCalfAtFoot(): bool
    {
        return ($this->nursingCalvesCount ?? 0) > 0;
    }

    public function startNewGestation(
        ?string $startDate,
        ?GestationStage $gestationStage,
        ?float $gestationMonths,
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
        // A calf at foot is not a mother: whether it nurses says nothing of its own state.
        if ($this->sex !== AnimalSex::FEMALE || $this->isNursing()) {
            return PhysiologicalState::UNKNOWN;
        }

        $hasActiveGestation = $this->hasActiveGestation();
        // Lactating: one of her own calves is still at foot.
        $isNursing = $this->hasCalfAtFoot();

        // Exposed to the bulls of a running service and not yet diagnosed pregnant: a
        // positive tacto is more informative than the exposure, so it takes precedence.
        if ($this->isInService && !$hasActiveGestation) {
            return $isNursing ? PhysiologicalState::IN_SERVICE_LACTATING : PhysiologicalState::IN_SERVICE;
        }

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
