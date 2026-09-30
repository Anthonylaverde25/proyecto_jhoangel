<?php

declare(strict_types=1);

namespace App\Core\Entities;

use App\Core\Enums\BirthOrderAnimalStatus;
use App\Core\Enums\BirthOutcome;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * One pregnant female of a birth order: the gestation it is expected to close and, once resolved,
 * what happened — the calf she had, or the loss.
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
        private readonly ?string $calfSex = null,
        private readonly ?string $calfBatchName = null
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

    public function isPending(): bool
    {
        return $this->status === BirthOrderAnimalStatus::PENDING;
    }

    /**
     * Records what happened to this female. A live calving names the calf it created and the batch
     * it was born in; a loss names neither.
     */
    public function resolve(
        BirthOutcome $outcome,
        string $eventDate,
        ?int $calfCaravanId,
        ?int $calfBatchId,
        DateTimeInterface $at,
        ?string $observations = null
    ): void {
        $this->status = BirthOrderAnimalStatus::forOutcome($outcome);
        $this->outcome = $outcome;
        $this->eventDate = substr($eventDate, 0, 10);
        $this->calfCaravanId = $outcome === BirthOutcome::LIVE ? $calfCaravanId : null;
        $this->calfBatchId = $outcome === BirthOutcome::LIVE ? $calfBatchId : null;
        $this->executedAt = DateTimeImmutable::createFromInterface($at);
        $this->observations = $observations;
    }

    public function markSkipped(): void
    {
        if ($this->isPending()) {
            $this->status = BirthOrderAnimalStatus::SKIPPED;
        }
    }
}
