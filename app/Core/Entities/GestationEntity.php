<?php

declare(strict_types=1);

namespace App\Core\Entities;

use App\Core\Enums\GestationResult;

class GestationEntity
{
    public function __construct(
        private ?int $id,
        private ?string $startDate,
        private ?string $estimatedDueDate,
        private bool $isCurrent,
        private ?GestationResult $result,
        private ?string $endDate,
        private ?string $notes
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStartDate(): ?string
    {
        return $this->startDate;
    }

    public function getEstimatedDueDate(): ?string
    {
        return $this->estimatedDueDate;
    }

    public function isCurrent(): bool
    {
        return $this->isCurrent;
    }

    public function getResult(): ?GestationResult
    {
        return $this->result;
    }

    public function getEndDate(): ?string
    {
        return $this->endDate;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function closeGestation(GestationResult $result, string $endDate, ?string $notes = null): void
    {
        $this->isCurrent = false;
        $this->result = $result;
        $this->endDate = $endDate;
        if ($notes !== null) {
            $this->notes = $notes;
        }
    }
}
