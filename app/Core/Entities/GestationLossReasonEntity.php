<?php

declare(strict_types=1);

namespace App\Core\Entities;

final class GestationLossReasonEntity
{
    public function __construct(
        private readonly ?int $id,
        private readonly string $name,
        private readonly string $code,
        private readonly ?string $description,
        private readonly bool $isActive = true
    ) {}

    public function getId(): ?int
    {
        return $this->id;
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

    public function isActive(): bool
    {
        return $this->isActive;
    }
}
