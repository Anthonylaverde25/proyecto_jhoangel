<?php

declare(strict_types=1);

namespace App\Core\Entities;

use App\Core\Enums\BirthOrderAnimalStatus;
use App\Core\Enums\BirthOutcome;
use App\Core\Enums\TransferOrderKind;
use App\Core\Enums\TransferOrderStatus;
use App\Core\Exceptions\BirthOrderDomainException;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * A birth order: which pregnant females are expected to calve, issued before the calving rounds
 * (PLANNED) or registered after the calvings happened (REGISTERED).
 *
 * It shares the lifecycle and the kind of transfer and weaning orders, and their codes. It differs
 * from them in two ways. It has no destination: a calf is born in its mother's batch. And it is
 * fulfilled over many rounds — the calving season lasts weeks — so PARTIAL is its normal state, and
 * a calving found at a round that the order did not list is added to the roll as unplanned.
 *
 * The status is never set by hand. It is recalculated against the roll: an order with no open line
 * left (PENDING or OVERDUE) is EXECUTED, one with some is PARTIAL. An overdue female keeps the order
 * open: she is an alert, not an outcome. What registers the calvings is the PAR-01 processing, or a
 * loss registered outside it; the status is their consequence, never their cause.
 */
final class BirthOrderEntity
{
    /**
     * Whether the roll was rewritten since the order was loaded, so the repository replaces it
     * instead of only updating statuses.
     */
    private bool $rosterReplaced = false;

    /**
     * @param BirthOrderAnimalEntity[] $animals
     * @param TransferOrderHistoryEntity[] $history the same history line as a transfer order's
     */
    public function __construct(
        private readonly ?int $id,
        private readonly int $companyId,
        private readonly string $code,
        private TransferOrderStatus $status,
        private readonly TransferOrderKind $kind,
        private ?string $periodStart,
        private ?string $periodEnd,
        private int $plannedHeadCount,
        private readonly ?int $requestedByUserId,
        private ?DateTimeInterface $emittedAt,
        private ?DateTimeInterface $printedAt = null,
        private ?DateTimeInterface $firstExecutedAt = null,
        private ?DateTimeInterface $closedAt = null,
        private ?string $responsable = null,
        private ?string $observations = null,
        private ?string $closingReason = null,
        private array $animals = [],
        private readonly array $history = [],
        private readonly ?DateTimeInterface $createdAt = null,
        private readonly ?string $requestedByUserName = null
    ) {
    }

    /**
     * Creates the order, as a draft or already issued.
     *
     * @param BirthOrderAnimalEntity[] $animals
     *
     * @throws BirthOrderDomainException
     */
    public static function create(
        bool $issued,
        int $companyId,
        string $code,
        TransferOrderKind $kind,
        ?string $periodStart,
        ?string $periodEnd,
        ?int $requestedByUserId,
        ?string $responsable,
        ?string $observations,
        array $animals
    ): self {
        self::assertRoll($animals);
        self::assertPeriod($periodStart, $periodEnd);

        return new self(
            id: null,
            companyId: $companyId,
            code: $code,
            status: $issued ? TransferOrderStatus::ISSUED : TransferOrderStatus::DRAFT,
            kind: $kind,
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            plannedHeadCount: count($animals),
            requestedByUserId: $requestedByUserId,
            emittedAt: $issued ? new DateTimeImmutable() : null,
            responsable: $responsable,
            observations: $observations,
            animals: $animals
        );
    }

    /**
     * Rewrites a draft: other females, other period or header.
     *
     * @param BirthOrderAnimalEntity[] $animals
     *
     * @throws BirthOrderDomainException
     */
    public function updateDraft(
        ?string $periodStart,
        ?string $periodEnd,
        ?string $responsable,
        ?string $observations,
        array $animals
    ): void {
        if (!$this->status->isEditable()) {
            throw BirthOrderDomainException::domainError(
                "La orden {$this->code} está {$this->status->label()}: sólo un borrador se puede modificar.",
                'NOT_EDITABLE'
            );
        }

        self::assertRoll($animals);
        self::assertPeriod($periodStart, $periodEnd);

        $this->periodStart = $periodStart;
        $this->periodEnd = $periodEnd;
        $this->responsable = $responsable;
        $this->observations = $observations;
        $this->animals = $animals;
        $this->plannedHeadCount = count($animals);
        $this->rosterReplaced = true;
    }

    /**
     * Draft → issued. From here the order holds its females against other birth orders and can be
     * printed and executed.
     *
     * @throws BirthOrderDomainException
     */
    public function issue(): void
    {
        if ($this->status !== TransferOrderStatus::DRAFT) {
            throw BirthOrderDomainException::invalidStateTransition($this->status->label(), TransferOrderStatus::ISSUED->label());
        }

        $this->status = TransferOrderStatus::ISSUED;
        $this->emittedAt = new DateTimeImmutable();
        $this->plannedHeadCount = count($this->animals);
    }

    public function isRosterReplaced(): bool
    {
        return $this->rosterReplaced;
    }

    /**
     * Stamps the paper. Not a state, and repeatable: the first print is the one that stays.
     */
    public function markPrinted(): void
    {
        if ($this->printedAt === null) {
            $this->printedAt = new DateTimeImmutable();
        }
    }

    /**
     * A calving found at the round that the order did not list. It joins the roll so the order
     * keeps the record of everything its rounds resolved.
     *
     * @throws BirthOrderDomainException
     */
    public function addUnplanned(int $motherCaravanId, ?int $gestationId, ?int $sourceBatchId): BirthOrderAnimalEntity
    {
        if ($this->animalByMotherId($motherCaravanId) !== null) {
            throw BirthOrderDomainException::domainError('La hembra ya figura en la orden.', 'DUPLICATED_ANIMAL');
        }

        $line = new BirthOrderAnimalEntity(
            id: null,
            motherCaravanId: $motherCaravanId,
            gestationId: $gestationId,
            sourceBatchId: $sourceBatchId,
            unplanned: true
        );

        $this->animals[] = $line;

        return $line;
    }

    /**
     * Registers one execution — a round — from the screen, from a registration or from a scanned
     * sheet: the calvings it resolved and the females it found past their due date.
     *
     * @param array<int, array{outcome: BirthOutcome, event_date: string, calf_caravan_id: ?int, calf_batch_id: ?int, observations: ?string, calf_sex?: ?string}> $resultByMotherId
     * @param array<int, array{reported_at: string, notes: ?string}> $overdueByMotherId
     *
     * @throws BirthOrderDomainException
     */
    public function recordExecution(array $resultByMotherId, DateTimeInterface $at, array $overdueByMotherId = []): void
    {
        $this->assertExecutable();

        foreach ($this->animals as $animal) {
            $motherId = $animal->getMotherCaravanId();
            $result = $resultByMotherId[$motherId] ?? null;
            $overdue = $overdueByMotherId[$motherId] ?? null;

            if ($result !== null && $animal->isOpen()) {
                $animal->resolve(
                    $result['outcome'],
                    $result['event_date'],
                    $result['calf_caravan_id'],
                    $result['calf_batch_id'],
                    $at,
                    $result['observations'],
                    $result['calf_sex'] ?? null
                );
            } elseif ($overdue !== null) {
                $animal->markOverdue($overdue['reported_at'], $overdue['notes'], $at);
            }
        }

        if ($this->firstExecutedAt === null) {
            $this->firstExecutedAt = DateTimeImmutable::createFromInterface($at);
        }

        $this->refreshStatus();
    }

    /**
     * A loss registered outside the sheet (Monitoreo Gestacional) for a female still open in the
     * order: her line closes with the real reason and the order is recalculated, so an abortion does
     * not leave it waiting for a calving that will never happen.
     *
     * @throws BirthOrderDomainException
     */
    public function resolveByExternalLoss(int $motherCaravanId, string $reasonCode, string $lossDate, DateTimeInterface $at): ?BirthOrderAnimalEntity
    {
        $this->assertExecutable();

        $line = $this->animalByMotherId($motherCaravanId);

        if ($line === null || !$line->isOpen()) {
            return null;
        }

        $line->closeByExternalLoss($reasonCode, $lossDate, $at);
        $this->refreshStatus();

        return $line;
    }

    /**
     * The season is over, knowing some females did not calve with it. Only from PARTIAL: an order
     * nothing was executed against is cancelled, not closed. The pending females keep their
     * gestation open — closing an order is not declaring a loss.
     *
     * @throws BirthOrderDomainException
     */
    public function closeIncomplete(string $reason): void
    {
        if ($this->status !== TransferOrderStatus::PARTIAL) {
            throw BirthOrderDomainException::invalidStateTransition($this->status->label(), TransferOrderStatus::CLOSED_INCOMPLETE->label());
        }

        $this->requireReason($reason, 'cerrar incompleta');

        // Overdue females are skipped too, but their alert lives in the gestation and stays open.
        foreach ($this->animals as $animal) {
            $animal->markSkipped();
        }

        $this->status = TransferOrderStatus::CLOSED_INCOMPLETE;
        $this->closingReason = trim($reason);
        $this->closedAt = new DateTimeImmutable();
    }

    /**
     * It did not happen, or it is being rebuilt. Never from PARTIAL: with calvings already
     * registered, cancelling would erase a fact. Discarding a draft needs no reason; cancelling an
     * issued order does.
     *
     * @throws BirthOrderDomainException
     */
    public function cancel(?string $reason): void
    {
        if ($this->status !== TransferOrderStatus::ISSUED && $this->status !== TransferOrderStatus::DRAFT) {
            throw BirthOrderDomainException::invalidStateTransition($this->status->label(), TransferOrderStatus::CANCELLED->label());
        }

        $reason = trim((string) $reason);

        if ($this->status === TransferOrderStatus::ISSUED) {
            $this->requireReason($reason, 'anular');
        }

        foreach ($this->animals as $animal) {
            $animal->markSkipped();
        }

        $this->status = TransferOrderStatus::CANCELLED;
        $this->closingReason = $reason !== '' ? $reason : null;
        $this->closedAt = new DateTimeImmutable();
    }

    public function animalByMotherId(int $motherCaravanId): ?BirthOrderAnimalEntity
    {
        foreach ($this->animals as $animal) {
            if ($animal->getMotherCaravanId() === $motherCaravanId) {
                return $animal;
            }
        }

        return null;
    }

    public function bornCount(): int
    {
        return $this->countByStatus(BirthOrderAnimalStatus::BORN);
    }

    /**
     * Calved a live calf that died at foot.
     */
    public function bornDiedCount(): int
    {
        return $this->countByStatus(BirthOrderAnimalStatus::BORN_DIED);
    }

    public function lostCount(): int
    {
        return $this->countByStatus(BirthOrderAnimalStatus::LOST);
    }

    /**
     * Lines closed by a loss registered outside the sheet.
     */
    public function externalLossCount(): int
    {
        return count(array_filter(
            $this->animals,
            fn (BirthOrderAnimalEntity $a) => $a->getStatus() === BirthOrderAnimalStatus::LOST && $a->getLossReasonCode() !== null
        ));
    }

    public function resolvedCount(): int
    {
        return $this->bornCount() + $this->bornDiedCount() + $this->lostCount();
    }

    /**
     * Still waiting for the calving: PENDING and OVERDUE.
     */
    public function pendingCount(): int
    {
        return count($this->openAnimals());
    }

    /**
     * Past their due date without calving: the order's open alerts.
     */
    public function overdueCount(): int
    {
        return $this->countByStatus(BirthOrderAnimalStatus::OVERDUE);
    }

    public function skippedCount(): int
    {
        return $this->countByStatus(BirthOrderAnimalStatus::SKIPPED);
    }

    public function outcomeCount(BirthOutcome $outcome): int
    {
        return count(array_filter($this->animals, fn (BirthOrderAnimalEntity $a) => $a->getOutcome() === $outcome));
    }

    public function unplannedCount(): int
    {
        return count(array_filter($this->animals, fn (BirthOrderAnimalEntity $a) => $a->isUnplanned()));
    }

    /**
     * The females still waiting for their calving, with or without an overdue alert: what a sheet
     * reprinted for the next round lists.
     *
     * @return BirthOrderAnimalEntity[]
     */
    public function openAnimals(): array
    {
        return array_values(array_filter($this->animals, fn (BirthOrderAnimalEntity $a) => $a->isOpen()));
    }

    /**
     * The batches the females were in when the order took them, by id, with their names when known.
     *
     * @return array<int, ?string>
     */
    public function sourceBatches(): array
    {
        $batches = [];

        foreach ($this->animals as $animal) {
            if ($animal->getSourceBatchId() !== null) {
                $batches[$animal->getSourceBatchId()] ??= $animal->getSourceBatchName();
            }
        }

        return $batches;
    }

    /**
     * @param BirthOrderAnimalEntity[] $animals
     *
     * @throws BirthOrderDomainException
     */
    private static function assertRoll(array $animals): void
    {
        if ($animals === []) {
            throw BirthOrderDomainException::domainError('Una orden de parición necesita al menos una hembra.', 'EMPTY_ROLL');
        }

        $seen = [];
        foreach ($animals as $animal) {
            if (isset($seen[$animal->getMotherCaravanId()])) {
                throw BirthOrderDomainException::domainError('Una hembra figura dos veces en la orden.', 'DUPLICATED_ANIMAL');
            }

            $seen[$animal->getMotherCaravanId()] = true;
        }
    }

    /**
     * @throws BirthOrderDomainException
     */
    private static function assertPeriod(?string $periodStart, ?string $periodEnd): void
    {
        if ($periodStart !== null && $periodEnd !== null && $periodEnd < $periodStart) {
            throw BirthOrderDomainException::domainError('El período de parición termina antes de empezar.', 'INVALID_PERIOD');
        }
    }

    /**
     * @throws BirthOrderDomainException
     */
    private function assertExecutable(): void
    {
        if (!$this->status->isOpen()) {
            throw BirthOrderDomainException::domainError(
                "La orden {$this->code} está {$this->status->label()} y no admite más partos.",
                'BIRTH_ORDER_NOT_EXECUTABLE'
            );
        }
    }

    /**
     * EXECUTED once no line is open, PARTIAL while one is.
     */
    private function refreshStatus(): void
    {
        $this->status = $this->pendingCount() === 0 ? TransferOrderStatus::EXECUTED : TransferOrderStatus::PARTIAL;

        if ($this->status === TransferOrderStatus::EXECUTED) {
            $this->closedAt = new DateTimeImmutable();
        }
    }

    private function countByStatus(BirthOrderAnimalStatus $status): int
    {
        return count(array_filter($this->animals, fn (BirthOrderAnimalEntity $a) => $a->getStatus() === $status));
    }

    private function requireReason(string $reason, string $action): void
    {
        if (trim($reason) === '') {
            throw BirthOrderDomainException::reasonRequired($action);
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCompanyId(): int
    {
        return $this->companyId;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getStatus(): TransferOrderStatus
    {
        return $this->status;
    }

    public function getKind(): TransferOrderKind
    {
        return $this->kind;
    }

    public function getPeriodStart(): ?string
    {
        return $this->periodStart;
    }

    public function getPeriodEnd(): ?string
    {
        return $this->periodEnd;
    }

    public function getPlannedHeadCount(): int
    {
        return $this->plannedHeadCount;
    }

    public function getRequestedByUserId(): ?int
    {
        return $this->requestedByUserId;
    }

    public function getEmittedAt(): ?DateTimeInterface
    {
        return $this->emittedAt;
    }

    public function getPrintedAt(): ?DateTimeInterface
    {
        return $this->printedAt;
    }

    public function getFirstExecutedAt(): ?DateTimeInterface
    {
        return $this->firstExecutedAt;
    }

    public function getClosedAt(): ?DateTimeInterface
    {
        return $this->closedAt;
    }

    public function getResponsable(): ?string
    {
        return $this->responsable;
    }

    public function getObservations(): ?string
    {
        return $this->observations;
    }

    public function getClosingReason(): ?string
    {
        return $this->closingReason;
    }

    /**
     * @return BirthOrderAnimalEntity[]
     */
    public function getAnimals(): array
    {
        return $this->animals;
    }

    /**
     * @return TransferOrderHistoryEntity[]
     */
    public function getHistory(): array
    {
        return $this->history;
    }

    public function getCreatedAt(): ?DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getRequestedByUserName(): ?string
    {
        return $this->requestedByUserName;
    }
}
