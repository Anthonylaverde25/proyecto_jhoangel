<?php

declare(strict_types=1);

namespace App\Core\Entities;

final class CompanyEntity
{
    public function __construct(
        private readonly ?int $id,
        private string $name,
        private ?string $renspa,
        private ?string $location,
        private bool $isActive = true,
        private ?string $role = null
    ) {}

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getRenspa(): ?string
    {
        return $this->renspa;
    }

    public function setRenspa(?string $renspa): void
    {
        $this->renspa = $renspa;
    }

    public function getLocation(): ?string
    {
        return $this->location;
    }

    public function setLocation(?string $location): void
    {
        $this->location = $location;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): void
    {
        $this->isActive = $isActive;
    }

    public function getRole(): ?string
    {
        return $this->role;
    }

    public function setRole(?string $role): void
    {
        $this->role = $role;
    }
}
