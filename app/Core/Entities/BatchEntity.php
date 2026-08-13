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
        private ?float $currentWeight = null,
        private ?int $caravansCount = null,
        private ?int $batchTypeId = null,
        private ?string $batchTypeName = null,
        private ?string $batchTypeCode = null,
        private bool $isSystem = false
    ) {
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

    public function getActivityId(): ?int
    {
        return $this->activityId;
    }

    public function getActivityName(): ?string
    {
        return $this->activityName;
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
}

