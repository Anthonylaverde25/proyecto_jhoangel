<?php

declare(strict_types=1);

namespace App\Core\ValueObjects;

final class SireEntry
{
    public function __construct(
        private readonly int $sireId,
        private readonly string $sireIdentification,
        private readonly bool $isConfirmed = false
    ) {}

    public function getSireId(): int
    {
        return $this->sireId;
    }

    public function getSireIdentification(): string
    {
        return $this->sireIdentification;
    }

    public function isConfirmed(): bool
    {
        return $this->isConfirmed;
    }
}
