<?php

declare(strict_types=1);

namespace App\Core\Entities;

class ActivityEntity
{
    public function __construct(
        private ?int $id,
        private string $name,
        private string $code,
        private bool $isEnabled = true
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

    public function isEnabled(): bool
    {
        return $this->isEnabled;
    }
}
