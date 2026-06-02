<?php

declare(strict_types=1);

namespace App\Core\Entities;

final class BirthHistoryEntity
{
    public function __construct(
        private int $gestationId,
        private int $motherId,
        private string $motherIdentification,
        private string $birthDate,
        private ?string $notes,
        private int $calfId,
        private string $calfIdentification,
        private bool $isNursing,
        private ?string $calfSex,
        private ?string $calfBatchName
    ) {
    }

    public function getGestationId(): int
    {
        return $this->gestationId;
    }

    public function getMotherId(): int
    {
        return $this->motherId;
    }

    public function getMotherIdentification(): string
    {
        return $this->motherIdentification;
    }

    public function getBirthDate(): string
    {
        return $this->birthDate;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function getCalfId(): int
    {
        return $this->calfId;
    }

    public function getCalfIdentification(): string
    {
        return $this->calfIdentification;
    }

    public function isNursing(): bool
    {
        return $this->isNursing;
    }

    public function getCalfSex(): ?string
    {
        return $this->calfSex;
    }

    public function getCalfBatchName(): ?string
    {
        return $this->calfBatchName;
    }
}
