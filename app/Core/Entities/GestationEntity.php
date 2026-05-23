<?php

declare(strict_types=1);

namespace App\Core\Entities;

use App\Core\Enums\GestationStage;
use App\Core\ValueObjects\SireEntry;

class GestationEntity
{
    /**
     * @param SireEntry[] $sires
     */
    public function __construct(
        private ?int $id,
        private ?string $startDate,
        private ?string $estimatedDueDate,
        private bool $isCurrent,
        private ?bool $success,
        private ?int $lossReasonId,
        private ?string $lossNotes,
        private ?string $endDate,
        private ?string $notes,
        private GestationStage $gestationStage,
        private float $gestationMonths,
        private array $sires = []
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

    public function getSuccess(): ?bool
    {
        return $this->success;
    }

    public function getLossReasonId(): ?int
    {
        return $this->lossReasonId;
    }

    public function getLossNotes(): ?string
    {
        return $this->lossNotes;
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

    /**
     * @return SireEntry[]
     */
    public function getSires(): array
    {
        return $this->sires;
    }

    /**
     * Get the confirmed sire, if one exists.
     */
    public function getConfirmedSire(): ?SireEntry
    {
        foreach ($this->sires as $sire) {
            if ($sire->isConfirmed()) {
                return $sire;
            }
        }
        return null;
    }

    public function addSire(SireEntry $sire): void
    {
        $this->sires[] = $sire;
    }

    public function confirmSire(int $sireId): void
    {
        foreach ($this->sires as $key => $sire) {
            if ($sire->getSireId() === $sireId) {
                $this->sires[$key] = new SireEntry(
                    $sire->getSireId(),
                    $sire->getSireIdentification(),
                    true
                );
            } else {
                $this->sires[$key] = new SireEntry(
                    $sire->getSireId(),
                    $sire->getSireIdentification(),
                    false
                );
            }
        }
    }


    public function closeGestation(
        bool $success,
        string $endDate,
        ?string $notes = null,
        ?int $lossReasonId = null,
        ?string $lossNotes = null
    ): void {
        $this->isCurrent = false;
        $this->success = $success;
        $this->endDate = $endDate;
        if ($notes !== null) {
            $this->notes = $notes;
        }

        if (!$success) {
            $this->lossReasonId = $lossReasonId;
            $this->lossNotes = $lossNotes;
        }
    }
}

