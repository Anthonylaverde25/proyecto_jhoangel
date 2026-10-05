<?php

declare(strict_types=1);

namespace App\Core\Entities;

use App\Core\Enums\BirthOrderAnimalStatus;
use App\Core\Enums\BirthOutcome;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * One pregnant female of a birth order: the gestation it is expected to close and, once resolved,
 * what happened — the calf she had, a calf that died, or the loss. Before that, it may carry an
 * overdue alert: she passed her due date without calving.
 *
 * There is no destination on the line: the calf is born in the batch its mother is in on the day it
 * is born. `calfBatchId` records where that turned out to be.
 */
final class BirthOrderAnimalEntity
{
    /**
     * @param list<array{id: int, identification: string, is_confirmed: bool}> $sires the candidate
     *        sires of the gestation. Read-only, for the review and the screen.
     */
    public function __construct(
        private readonly ?int $id,
        private readonly int $motherCaravanId,
        private readonly ?int $gestationId,
        private readonly ?int $sourceBatchId,
        private readonly bool $unplanned = false,
        private BirthOrderAnimalStatus $status = BirthOrderAnimalStatus::PENDING,
        private ?BirthOutcome $outcome = null,
        private ?string $eventDate = null,
        private ?int $calfCaravanId = null,
        private ?int $calfBatchId = null,
        private ?DateTimeInterface $executedAt = null,
        private ?string $observations = null,
        private readonly ?string $motherIdentification = null,
        private readonly ?string $motherCategoryLabel = null,
        private readonly ?string $sourceBatchName = null,
        private readonly ?int $currentBatchId = null,
        private readonly ?string $currentBatchName = null,
        private readonly ?string $gestationStartDate = null,
        private readonly ?string $estimatedDueDate = null,
        private readonly ?string $gestationStage = null,
        private readonly array $sires = [],
        private readonly ?string $calfIdentification = null,
        private ?string $calfSex = null,
        private readonly ?string $calfBatchName = null,
        private ?string $lossReasonCode = null,
        private ?string $overdueReportedAt = null,
        private ?string $overdueNotes = null,
        private readonly ?string $lossReasonLabel = null
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMotherCaravanId(): int
    {
        return $this->motherCaravanId;
    }

    public function getGestationId(): ?int
    {
        return $this->gestationId;
    }

    public function getSourceBatchId(): ?int
    {
        return $this->sourceBatchId;
    }

    public function isUnplanned(): bool
    {
        return $this->unplanned;
    }

    public function getStatus(): BirthOrderAnimalStatus
    {
        return $this->status;
    }

    public function getOutcome(): ?BirthOutcome
    {
        return $this->outcome;
    }

    public function getEventDate(): ?string
    {
        return $this->eventDate;
    }

    public function getCalfCaravanId(): ?int
    {
        return $this->calfCaravanId;
    }

    public function getCalfBatchId(): ?int
    {
        return $this->calfBatchId;
    }

    public function getExecutedAt(): ?DateTimeInterface
    {
        return $this->executedAt;
    }

    public function getObservations(): ?string
    {
        return $this->observations;
    }

    public function getMotherIdentification(): ?string
    {
        return $this->motherIdentification;
    }

    public function getMotherCategoryLabel(): ?string
    {
        return $this->motherCategoryLabel;
    }

    public function getSourceBatchName(): ?string
    {
        return $this->sourceBatchName;
    }

    public function getCurrentBatchId(): ?int
    {
        return $this->currentBatchId;
    }

    public function getCurrentBatchName(): ?string
    {
        return $this->currentBatchName;
    }

    public function getGestationStartDate(): ?string
    {
        return $this->gestationStartDate;
    }

    public function getEstimatedDueDate(): ?string
    {
        return $this->estimatedDueDate;
    }

    public function getGestationStage(): ?string
    {
        return $this->gestationStage;
    }

    /**
     * @return list<array{id: int, identification: string, is_confirmed: bool}>
     */
    public function getSires(): array
    {
        return $this->sires;
    }

    public function getCalfIdentification(): ?string
    {
        return $this->calfIdentification;
    }

    public function getCalfSex(): ?string
    {
        return $this->calfSex;
    }

    public function getCalfBatchName(): ?string
    {
        return $this->calfBatchName;
    }

    /**
     * The code of the loss reason of a line closed by a loss registered outside the sheet.
     */
    public function getLossReasonCode(): ?string
    {
        return $this->lossReasonCode;
    }

    public function getLossReasonLabel(): ?string
    {
        return $this->lossReasonLabel;
    }

    /**
     * The day an N was observed: she passed her due date without calving. Kept after she calves,
     * to measure the delay.
     */
    public function getOverdueReportedAt(): ?string
    {
        return $this->overdueReportedAt;
    }

    public function getOverdueNotes(): ?string
    {
        return $this->overdueNotes;
    }

    public function isPending(): bool
    {
        return $this->status === BirthOrderAnimalStatus::PENDING;
    }

    public function isOverdue(): bool
    {
        return $this->status === BirthOrderAnimalStatus::OVERDUE;
    }

    /**
     * Still waiting for the calving, with or without an overdue alert.
     */
    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    public function isResolved(): bool
    {
        return $this->status->isResolved();
    }

    /**
     * Records what happened to this female, from PENDING or from OVERDUE. A live calving names the
     * calf it created and the batch it was born in; a calf that died names at most its sex.
     */
    public function resolve(
        BirthOutcome $outcome,
        string $eventDate,
        ?int $calfCaravanId,
        ?int $calfBatchId,
        DateTimeInterface $at,
        ?string $observations = null,
        ?string $calfSex = null
    ): void {
        if (!$this->isOpen()) {
            return;
        }

        $live = $outcome === BirthOutcome::LIVE;

        $this->status = BirthOrderAnimalStatus::forOutcome($outcome);
        $this->outcome = $outcome;
        $this->eventDate = substr($eventDate, 0, 10);
        $this->calfCaravanId = $live ? $calfCaravanId : null;
        $this->calfBatchId = $live ? $calfBatchId : null;
        $this->calfSex = $calfSex;
        $this->executedAt = DateTimeImmutable::createFromInterface($at);
        $this->observations = $observations;
    }

    /**
     * N: she passed her due date without calving. The line stays open, with the alert.
     */
    public function markOverdue(string $reportedAt, ?string $notes, DateTimeInterface $at): void
    {
        if (!$this->isPending()) {
            return;
        }

        $this->status = BirthOrderAnimalStatus::OVERDUE;
        $this->overdueReportedAt = substr($reportedAt, 0, 10);
        $this->overdueNotes = $notes;
        $this->executedAt = DateTimeImmutable::createFromInterface($at);
    }

    /**
     * Her pregnancy was lost and registered outside the sheet (Monitoreo Gestacional): the line is
     * closed with the real reason and no outcome.
     */
    public function closeByExternalLoss(string $reasonCode, string $lossDate, DateTimeInterface $at): void
    {
        if (!$this->isOpen()) {
            return;
        }

        $this->status = BirthOrderAnimalStatus::LOST;
        $this->outcome = null;
        $this->lossReasonCode = $reasonCode;
        $this->eventDate = substr($lossDate, 0, 10);
        $this->executedAt = DateTimeImmutable::createFromInterface($at);
    }

    /**
     * Whether a reloaded sheet says what is already registered: the same outcome and, for a live
     * calf, the same calf tag (case-insensitive).
     */
    public function matches(BirthOutcome $outcome, ?string $calfTag): bool
    {
        if ($this->outcome !== $outcome) {
            return false;
        }

        if ($outcome !== BirthOutcome::LIVE) {
            return true;
        }

        return $calfTag !== null
            && $this->calfIdentification !== null
            && mb_strtoupper(trim($calfTag)) === mb_strtoupper(trim($this->calfIdentification));
    }

    /**
     * What is registered, for a person: "Nació muerto", "Pérdida registrada aparte: Aborto".
     */
    public function resolvedLabel(): string
    {
        if ($this->outcome !== null) {
            return $this->outcome->label();
        }

        if ($this->lossReasonCode !== null) {
            return 'Pérdida registrada aparte: ' . ($this->lossReasonLabel ?? $this->lossReasonCode);
        }

        return strtolower($this->status->name);
    }

    public function markSkipped(): void
    {
        if ($this->isOpen()) {
            $this->status = BirthOrderAnimalStatus::SKIPPED;
        }
    }
}
