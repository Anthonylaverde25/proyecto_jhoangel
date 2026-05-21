<?php

declare(strict_types=1);

namespace App\Core\Entities;

final class BatchTypeEntity
{
    public function __construct(
        private readonly ?int $id,
        private readonly int $companyId,
        private string $name,
        private string $code,
        private ?string $description = null,
        private ?string $color = null,
        private ?string $icon = null,
        private bool $isActive = true
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCompanyId(): int
    {
        return $this->companyId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getColor(): ?string
    {
        return $this->color;
    }

    public function getIcon(): ?string
    {
        return $this->icon;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function updateDetails(
        string $name,
        string $code,
        ?string $description,
        ?string $color,
        ?string $icon,
        bool $isActive
    ): void {
        $this->name = $name;
        $this->code = $code;
        $this->description = $description;
        $this->color = $color;
        $this->icon = $icon;
        $this->isActive = $isActive;
    }
}
