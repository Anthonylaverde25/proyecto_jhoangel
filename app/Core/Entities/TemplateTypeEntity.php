<?php

declare(strict_types=1);

namespace App\Core\Entities;

final class TemplateTypeEntity
{
    public function __construct(
        private readonly ?int $id,
        private readonly int $companyId,
        private string $name,
        private string $code,
        private ?string $icon = null,
        private ?string $color = null,
        private ?string $description = null,
        private bool $isActive = true,
        private ?\DateTimeInterface $createdAt = null
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

    public function getIcon(): ?string
    {
        return $this->icon;
    }

    public function getColor(): ?string
    {
        return $this->color;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }
}
