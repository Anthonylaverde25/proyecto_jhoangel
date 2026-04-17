<?php

declare(strict_types=1);

namespace App\Core\Entities;

final class FarmEntity
{
    public function __construct(
        private readonly ?int $id,
        private string $name,
        private ?string $location,
        private int $providerId,
        private bool $isActive = true,
        private ?\DateTimeInterface $createdAt = null
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

    public function getLocation(): ?string
    {
        return $this->location;
    }

    public function getProviderId(): int
    {
        return $this->providerId;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function updateDetails(string $name, ?string $location): void
    {
        $this->name = $name;
        $this->location = $location;
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
