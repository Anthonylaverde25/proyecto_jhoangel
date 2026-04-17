<?php

declare(strict_types=1);

namespace App\Core\Entities;

final class BatchEntity
{
    public function __construct(
        private readonly ?int $id,
        private string $name,
        private int $farmId,
        private ?string $observaciones,
        private bool $isActive = true,
        private ?\DateTimeInterface $createdAt = null,
        private ?string $farmName = null,
        private ?int $providerId = null,
        private ?string $providerName = null
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getFarmId(): int
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
}
