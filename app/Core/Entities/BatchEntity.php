<?php

declare(strict_types=1);

namespace App\Core\Entities;

final class BatchEntity
{
    public function __construct(
        private readonly ?int $id,
        private string $name,
        private ?int $farmId,
        private ?string $observaciones,
        private bool $isActive = true,
        private ?\DateTimeInterface $createdAt = null,
        private ?string $farmName = null,
        private ?int $providerId = null,
        private ?string $providerName = null,
        private ?int $activityId = null,
        private ?string $activityName = null,
        private ?string $activityCode = null,
        private ?float $currentWeight = null,
        private ?float $totalWeight = null,
        private ?int $weighedCount = null,
        private ?int $caravansCount = null,
        private ?int $batchTypeId = null,
        private ?string $batchTypeName = null,
        private ?string $batchTypeCode = null,
        private bool $isSystem = false,
        private ?string $renspa = null,
        private bool $knowsToEat = false,
        private ?bool $isConfined = null,
        private ?int $ageInMonths = null,
        private ?float $minWeight = null,
        private ?float $maxWeight = null,
        private ?ServiceBatchDetailEntity $serviceDetail = null,
        private bool $wasEmptied = false
    ) {
    }

    public function getServiceDetail(): ?ServiceBatchDetailEntity
    {
        return $this->serviceDetail;
    }

    public function setServiceDetail(?ServiceBatchDetailEntity $serviceDetail): void
    {
        $this->serviceDetail = $serviceDetail;
    }

    public function isServiceBatch(): bool
    {
        return $this->batchTypeCode === 'SERVICE' || $this->serviceDetail !== null;
    }

    public function getCurrentWeight(): ?float
    {
        return $this->currentWeight;
    }

    public function setCurrentWeight(?float $currentWeight): void
    {
        $this->currentWeight = $currentWeight;
    }

    public function getCaravansCount(): ?int
    {
        return $this->caravansCount;
    }

    public function setCaravansCount(?int $caravansCount): void
    {
        $this->caravansCount = $caravansCount;
    }

    /**
     * Measured mass of the batch: additive and conserved when animals are split between
     * batches, which is what makes a transfer readable as a transfer and not as a loss.
     */
    public function getTotalWeight(): ?float
    {
        return $this->totalWeight;
    }

    public function setTotalWeight(?float $totalWeight): void
    {
        $this->totalWeight = $totalWeight;
    }

    /** Head the average was computed over; may be smaller than the batch. */
    public function getWeighedCount(): ?int
    {
        return $this->weighedCount;
    }

    public function setWeighedCount(?int $weighedCount): void
    {
        $this->weighedCount = $weighedCount;
    }

    public function getActivityId(): ?int
    {
        return $this->activityId;
    }

    public function getActivityName(): ?string
    {
        return $this->activityName;
    }

    public function getActivityCode(): ?string
    {
        return $this->activityCode;
    }


    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getFarmId(): ?int
    {
        return $this->farmId;
    }

    public function getObservaciones(): ?string
    {
        return $this->observaciones;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getFarmName(): ?string
    {
        return $this->farmName;
    }

    public function setFarmName(?string $farmName): void
    {
        $this->farmName = $farmName;
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

    public function updateDetails(string $name, ?string $observaciones): void
    {
        $this->name = $name;
        $this->observaciones = $observaciones;
    }

    public function activate(): void
    {
        $this->isActive = true;
    }

    public function deactivate(): void
    {
        $this->isActive = false;
    }

    public function setActivityId(int $activityId): void
    {
        $this->activityId = $activityId;
    }

    public function getBatchTypeId(): ?int
    {
        return $this->batchTypeId;
    }

    public function getBatchTypeName(): ?string
    {
        return $this->batchTypeName;
    }

    public function getBatchTypeCode(): ?string
    {
        return $this->batchTypeCode;
    }

    public function setBatchTypeId(?int $batchTypeId): void
    {
        $this->batchTypeId = $batchTypeId;
    }

    public function isSystem(): bool
    {
        return $this->isSystem;
    }

    public function isExternal(): bool
    {
        return $this->providerId !== null;
    }

    public function isOwn(): bool
    {
        return !$this->isExternal();
    }

    public function getRenspa(): ?string
    {
        return $this->renspa;
    }

    public function setRenspa(?string $renspa): void
    {
        $this->renspa = $renspa;
    }

    public function knowsToEat(): bool
    {
        return $this->knowsToEat;
    }

    public function setKnowsToEat(bool $knowsToEat): void
    {
        $this->knowsToEat = $knowsToEat;
    }

    /**
     * Management system of this batch instance: true = confined (pen, trough feeding),
     * false = extensive (pasture), null = nobody declared it yet. Orthogonal to the
     * batch type, asked for in every productive activity, and mutable.
     *
     * The null is not an oversight: collapsing it into false would make the system
     * assert a fact only the producer knows.
     */
    public function isConfined(): ?bool
    {
        return $this->isConfined;
    }

    public function setIsConfined(?bool $isConfined): void
    {
        $this->isConfined = $isConfined;
    }

    /**
     * Whether the management system is a fact somebody stated, as opposed to a
     * question nobody has been asked.
     */
    public function declaresManagementSystem(): bool
    {
        return $this->isConfined !== null;
    }

    public function getAgeInMonths(): ?int
    {
        return $this->ageInMonths;
    }

    public function setAgeInMonths(?int $ageInMonths): void
    {
        $this->ageInMonths = $ageInMonths;
    }

    public function getMinWeight(): ?float
    {
        return $this->minWeight;
    }

    public function setMinWeight(?float $minWeight): void
    {
        $this->minWeight = $minWeight;
    }

    public function getMaxWeight(): ?float
    {
        return $this->maxWeight;
    }

    public function setMaxWeight(?float $maxWeight): void
    {
        $this->maxWeight = $maxWeight;
    }

    /**
     * True when at least one animal left this batch: the batch is reusable and keeps
     * its activity, type and management system, but it is empty because it was
     * emptied, not because it never held animals.
     */
    public function wasEmptied(): bool
    {
        return $this->wasEmptied;
    }

    public function setWasEmptied(bool $wasEmptied): void
    {
        $this->wasEmptied = $wasEmptied;
    }

    public function isWeaningBatch(): bool
    {
        return $this->batchTypeCode === 'WEANING';
    }
}

