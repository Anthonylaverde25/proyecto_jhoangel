<?php

declare(strict_types=1);

namespace App\Core\Entities;

use App\Core\Enums\GestationResult;
use App\Core\Enums\GestationStage;

class GestationEntity
{
    public function __construct(
        private ?int $id,
        private ?string $startDate,
        private ?string $estimatedDueDate,
        private bool $isCurrent,
        private ?GestationResult $result,
        private ?string $endDate,
        private ?string $notes,
        private GestationStage $gestationStage,
        private float $gestationMonths
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
        if ($this->estimatedDueDate === null && $this->startDate !== null) {
            try {
                $startDateObj = new \DateTime($this->startDate);
                $daysRemaining = (int) round((9.0 - $this->gestationMonths) * 30.4375);
                if ($daysRemaining < 0) {
                    $daysRemaining = 0;
                }
                $dueDateObj = clone $startDateObj;
                $dueDateObj->modify("+{$daysRemaining} days");
                return $dueDateObj->format('Y-m-d');
            } catch (\Exception $e) {
                return null;
            }
        }
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

    public function getGestationStage(): GestationStage
    {
        return $this->gestationStage;
    }

    public function getGestationMonths(): float
    {
        return $this->gestationMonths;
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
