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
        private ?string $calfBatchName,
        private ?int $calfBatchId = null,
        private ?int $motherBatchId = null,
        private ?string $motherBatchName = null,
        /** The open order — of weaning or of transfer — that already holds this calf, if any. */
        private ?string $openOrderCode = null,
        /** The calf's current C/S: the reference a new one is chosen against. */
        private ?int $calfCategoryId = null,
        private ?int $calfSubcategoryId = null
    ) {
    }

    public function getCalfCategoryId(): ?int
    {
        return $this->calfCategoryId;
    }

    public function getCalfSubcategoryId(): ?int
    {
        return $this->calfSubcategoryId;
    }

    public function getOpenOrderCode(): ?string
    {
        return $this->openOrderCode;
    }

    public function holdByOpenOrder(?string $code): void
    {
        $this->openOrderCode = $code;
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

    public function getCalfBatchId(): ?int
    {
        return $this->calfBatchId;
    }

    public function getMotherBatchId(): ?int
    {
        return $this->motherBatchId;
    }

    public function getMotherBatchName(): ?string
    {
        return $this->motherBatchName;
    }
}
