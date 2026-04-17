<?php

declare(strict_types=1);

namespace App\Core\Entities;

final class ProviderEntity
{
    public function __construct(
        private readonly ?int $id,
        private string $name,
        private ?string $commercialName,
        private string $cuit,
        private ?string $location,
        private ?string $email,
        private ?string $phone,
        private bool $isActive = true,
        private ?\DateTimeInterface $createdAt = null,
        /** @var FarmEntity[] */
        private array $farms = []
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

    public function getCommercialName(): ?string
    {
        return $this->commercialName;
    }

    public function getCuit(): string
    {
        return $this->cuit;
    }

    public function getLocation(): ?string
    {
        return $this->location;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    /**
     * @return FarmEntity[]
     */
    public function getFarms(): array
    {
        return $this->farms;
    }

    public function updateDetails(
        string $name,
        ?string $commercialName,
        ?string $location,
        ?string $email,
        ?string $phone
    ): void {
        $this->name = $name;
        $this->commercialName = $commercialName;
        $this->location = $location;
        $this->email = $email;
        $this->phone = $phone;
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
