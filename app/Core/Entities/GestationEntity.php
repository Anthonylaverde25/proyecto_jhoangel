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
        private array $sires = [],
        private ?int $serviceOrderId = null,
        /** The day an N reported she passed her due date without calving. The alert is open while the gestation is current. */
        private ?string $calvingOverdueReportedAt = null,
        /** The code of the loss reason it was closed with (STILLBORN, ABORTION…), when known. Read-only. */
        private readonly ?string $lossReasonCode = null
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getServiceOrderId(): ?int
    {
        return $this->serviceOrderId;
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

    public function getLossReasonCode(): ?string
    {
        return $this->lossReasonCode;
    }

    /**
     * Closed by a calf born dead: a loss charged to the mother's reproductive record.
     */
    public function isStillbirth(): bool
    {
        return $this->success === false && $this->lossReasonCode === 'STILLBORN';
    }

    public function getCalvingOverdueReportedAt(): ?string
    {
        return $this->calvingOverdueReportedAt;
    }

    /**
     * She passed her due date without calving and has not calved or lost the pregnancy since.
     */
    public function isCalvingOverdue(): bool
    {
        return $this->isCurrent && $this->calvingOverdueReportedAt !== null;
    }

    /**
     * N: the round found her past her due date without calving. Only on a current gestation, and
     * the first report is the one kept: it is when the risk started.
     */
    public function reportCalvingOverdue(string $reportedAt): void
    {
        if ($this->isCurrent && $this->calvingOverdueReportedAt === null) {
            $this->calvingOverdueReportedAt = substr($reportedAt, 0, 10);
        }
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

