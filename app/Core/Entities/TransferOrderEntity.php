<?php

declare(strict_types=1);

namespace App\Core\Entities;

use App\Core\Enums\TransferOrderAnimalStatus;
use App\Core\Enums\TransferOrderCategoryMode;
use App\Core\Enums\TransferOrderKind;
use App\Core\Enums\TransferOrderStatus;
use App\Core\Exceptions\TransferOrderDomainException;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * A transfer order: which animals leave which batch, towards which productive activity and
 * into which batches, committed before the movement happens.
 *
 * The status is never set by hand. It is recalculated against the roll: an order with no
 * PENDING line left is EXECUTED, one with some is PARTIAL. What moves animals is the CACT-01
 * processing; the status is its consequence, never its cause.
 */
final class TransferOrderEntity
{
    public const MODE_SINGLE = 'single';
    public const MODE_PER_ANIMAL = 'per_animal';

    /**
     * @param TransferOrderDestinationEntity[] $destinations
     * @param TransferOrderAnimalEntity[] $animals
     * @param TransferOrderHistoryEntity[] $history
     */
    public function __construct(
        private readonly ?int $id,
        private readonly int $companyId,
        private readonly int $sourceBatchId,
        private int $destinationActivityId,
        private readonly string $code,
        private TransferOrderStatus $status,
        private string $destinationMode,
        private int $plannedHeadCount,
        private readonly string $movementDate,
        private readonly ?int $requestedByUserId,
        private ?DateTimeInterface $emittedAt,
        private ?DateTimeInterface $printedAt = null,
        private ?DateTimeInterface $firstExecutedAt = null,
        private ?DateTimeInterface $closedAt = null,
        private readonly ?string $responsable = null,
        private readonly ?string $observations = null,
        private ?string $closingReason = null,
        private array $destinations = [],
        private array $animals = [],
        private readonly array $history = [],
        private readonly ?DateTimeInterface $createdAt = null,
        private readonly ?string $sourceBatchName = null,
        private readonly ?string $sourceActivityName = null,
        private readonly ?string $destinationActivityName = null,
        private readonly ?string $requestedByUserName = null,
        private readonly TransferOrderKind $kind = TransferOrderKind::PLANNED,
        private TransferOrderCategoryMode $categoryMode = TransferOrderCategoryMode::KEEP
    ) {
    }

    /**
     * Whether the destinations and the roll were rewritten since the order was loaded, so the
     * repository replaces them instead of only updating statuses.
     */
    private bool $rosterReplaced = false;

    /**
     * Creates the order, as a draft or already issued.
     *
     * An issued order commits its animals from this moment; a draft commits nothing until it is
     * issued, which is when the commitment is checked.
     *
     * @param TransferOrderDestinationEntity[] $destinations
     * @param TransferOrderAnimalEntity[] $animals
     *
     * @throws TransferOrderDomainException
     */
    public static function create(
        bool $issued,
        int $companyId,
        int $sourceBatchId,
        int $destinationActivityId,
        string $code,
        string $destinationMode,
        string $movementDate,
        ?int $requestedByUserId,
        ?string $responsable,
        ?string $observations,
        array $destinations,
        array $animals,
        TransferOrderKind $kind = TransferOrderKind::PLANNED,
        TransferOrderCategoryMode $categoryMode = TransferOrderCategoryMode::KEEP
    ): self {
        self::assertRoster($destinationMode, $destinations, $animals);
        self::assertCategoryTargets($categoryMode, $animals);

        return new self(
            id: null,
            companyId: $companyId,
            sourceBatchId: $sourceBatchId,
            destinationActivityId: $destinationActivityId,
            code: $code,
            status: $issued ? TransferOrderStatus::ISSUED : TransferOrderStatus::DRAFT,
            destinationMode: $destinationMode,
            plannedHeadCount: count($animals),
            movementDate: $movementDate,
            requestedByUserId: $requestedByUserId,
            emittedAt: $issued ? new DateTimeImmutable() : null,
            responsable: $responsable,
            observations: $observations,
            destinations: $destinations,
            animals: $animals,
            kind: $kind,
            categoryMode: $categoryMode
        );
    }

    /**
     * Rewrites a draft: another destination activity, other destinations, other animals.
     *
     * @param TransferOrderDestinationEntity[] $destinations
     * @param TransferOrderAnimalEntity[] $animals
     *
     * @throws TransferOrderDomainException
     */
    public function updateDraft(
        int $destinationActivityId,
        string $destinationMode,
        array $destinations,
        array $animals,
        TransferOrderCategoryMode $categoryMode = TransferOrderCategoryMode::KEEP
    ): void {
        if (!$this->status->isEditable()) {
            throw TransferOrderDomainException::domainError(
                "La orden {$this->code} está {$this->status->label()}: sólo un borrador se puede modificar.",
                'NOT_EDITABLE'
            );
        }

        self::assertRoster($destinationMode, $destinations, $animals);
        self::assertCategoryTargets($categoryMode, $animals);

        $this->destinationActivityId = $destinationActivityId;
        $this->destinationMode = $destinationMode;
        $this->categoryMode = $categoryMode;
        $this->destinations = $destinations;
        $this->animals = $animals;
        $this->plannedHeadCount = count($animals);
        $this->rosterReplaced = true;
    }

    /**
     * Draft → issued. From here the order commits its animals and can be printed and executed.
     *
     * @throws TransferOrderDomainException
     */
    public function issue(): void
    {
        if ($this->status !== TransferOrderStatus::DRAFT) {
            throw TransferOrderDomainException::invalidStateTransition($this->status->label(), TransferOrderStatus::ISSUED->label());
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
     * @param TransferOrderDestinationEntity[] $destinations
     * @param TransferOrderAnimalEntity[] $animals
     *
     * @throws TransferOrderDomainException
     */
    private static function assertRoster(string $destinationMode, array $destinations, array $animals): void
    {
        if ($animals === []) {
            throw TransferOrderDomainException::domainError('Una orden de transferencia necesita al menos un animal.', 'EMPTY_ROLL');
        }

        if (!in_array($destinationMode, [self::MODE_SINGLE, self::MODE_PER_ANIMAL], true)) {
            throw TransferOrderDomainException::domainError("Modo de destino desconocido: {$destinationMode}.", 'INVALID_DESTINATION_MODE');
        }

        $keys = [];
        foreach ($destinations as $destination) {
            $keys[$destination->getKey()] = true;
        }

        foreach ($animals as $animal) {
            $key = $animal->getDestinationKey();

            if ($key !== null && !isset($keys[$key])) {
                throw TransferOrderDomainException::domainError("Un animal apunta a un destino que la orden no declara ({$key}).", 'UNKNOWN_DESTINATION');
            }

            // With one destination for everybody there is nothing left to decide at the chute.
            if ($key === null && $destinationMode === self::MODE_SINGLE) {
                throw TransferOrderDomainException::domainError('Con destino único todos los animales van al mismo lote.', 'DESTINATION_MISSING');
            }
        }
    }

    /**
     * Only a DECLARED order carries target categories, and it carries at least one: an order that
     * says the category changes and names no change for anybody says nothing. In the other two
     * modes a target would be printed nowhere and applied silently, so it is refused.
     *
     * @param TransferOrderAnimalEntity[] $animals
     *
     * @throws TransferOrderDomainException
     */
    private static function assertCategoryTargets(TransferOrderCategoryMode $categoryMode, array $animals): void
    {
        $withTarget = array_filter($animals, fn (TransferOrderAnimalEntity $a) => $a->hasTargetCategory());

        if ($categoryMode === TransferOrderCategoryMode::DECLARED && $withTarget === []) {
            throw TransferOrderDomainException::domainError(
                'La orden dice que la categoría cambia, pero no asigna la nueva categoría a ningún animal.',
                'CATEGORY_TARGET_MISSING'
            );
        }

        if ($categoryMode !== TransferOrderCategoryMode::DECLARED && $withTarget !== []) {
            throw TransferOrderDomainException::domainError(
                'Sólo una orden con la categoría declarada puede asignar categorías nuevas a sus animales.',
                'CATEGORY_TARGET_NOT_EXPECTED'
            );
        }
    }

    /**
     * Stamps the paper. Not a state, and repeatable: reprinting is not a new fact, so the first
     * print is the one that stays.
     */
    public function markPrinted(): void
    {
        if ($this->printedAt === null) {
            $this->printedAt = new DateTimeImmutable();
        }
    }

    /**
     * Registers one execution, from the screen or from a scanned sheet.
     *
     * @param array<int, int> $movementIdByCaravanId the movement that fulfilled each line
     * @param array<string, array{id: int, name: string}> $batchByDestinationKey the batch each destination landed in
     *
     * @throws TransferOrderDomainException
     */
    public function recordExecution(array $movementIdByCaravanId, array $batchByDestinationKey, DateTimeInterface $at): void
    {
        if (!$this->status->isOpen()) {
            throw TransferOrderDomainException::domainError(
                "La orden {$this->code} está {$this->status->label()} y no admite más movimientos.",
                'TRANSFER_ORDER_NOT_EXECUTABLE'
            );
        }

        foreach ($this->animals as $animal) {
            $movementId = $movementIdByCaravanId[$animal->getCaravanId()] ?? null;

            if ($movementId !== null && $animal->isPending()) {
                $animal->markMoved($movementId, $at);
            }
        }

        foreach ($this->destinations as $destination) {
            $batch = $batchByDestinationKey[$destination->getKey()] ?? null;

            if ($batch !== null) {
                $destination->resolveTo($batch['id'], $batch['name']);
            }
        }

        if ($this->firstExecutedAt === null) {
            $this->firstExecutedAt = DateTimeImmutable::createFromInterface($at);
        }

        $this->status = $this->pendingCount() === 0 ? TransferOrderStatus::EXECUTED : TransferOrderStatus::PARTIAL;

        if ($this->status === TransferOrderStatus::EXECUTED) {
            $this->closedAt = new DateTimeImmutable();
        }
    }

    /**
     * Done, knowing some did not travel. Only from PARTIAL: an order nothing was executed
     * against is cancelled, not closed.
     *
     * @throws TransferOrderDomainException
     */
    public function closeIncomplete(string $reason): void
    {
        if ($this->status !== TransferOrderStatus::PARTIAL) {
            throw TransferOrderDomainException::invalidStateTransition($this->status->label(), TransferOrderStatus::CLOSED_INCOMPLETE->label());
        }

        $this->requireReason($reason, 'cerrar incompleta');

        foreach ($this->animals as $animal) {
            $animal->markSkipped();
        }

        $this->status = TransferOrderStatus::CLOSED_INCOMPLETE;
        $this->closingReason = trim($reason);
        $this->closedAt = new DateTimeImmutable();
    }

    /**
     * It did not happen, or it is being rebuilt. Never from PARTIAL: with animals already
     * moved, cancelling would erase a fact. That case is closed incomplete.
     *
     * Discarding a draft needs no reason: nothing was committed. Cancelling an issued order
     * does, because somebody may have acted on it.
     *
     * @throws TransferOrderDomainException
     */
    public function cancel(?string $reason): void
    {
        if ($this->status !== TransferOrderStatus::ISSUED && $this->status !== TransferOrderStatus::DRAFT) {
            throw TransferOrderDomainException::invalidStateTransition($this->status->label(), TransferOrderStatus::CANCELLED->label());
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

    public function destinationByKey(string $key): ?TransferOrderDestinationEntity
    {
        foreach ($this->destinations as $destination) {
            if ($destination->getKey() === $key) {
                return $destination;
            }
        }

        return null;
    }

    public function animalByCaravanId(int $caravanId): ?TransferOrderAnimalEntity
    {
        foreach ($this->animals as $animal) {
            if ($animal->getCaravanId() === $caravanId) {
                return $animal;
            }
        }

        return null;
    }

    public function movedCount(): int
    {
        return $this->countByStatus(TransferOrderAnimalStatus::MOVED);
    }

    public function pendingCount(): int
    {
        return $this->countByStatus(TransferOrderAnimalStatus::PENDING);
    }

    public function skippedCount(): int
    {
        return $this->countByStatus(TransferOrderAnimalStatus::SKIPPED);
    }

    /**
     * @return TransferOrderAnimalEntity[]
     */
    public function pendingAnimals(): array
    {
        return array_values(array_filter($this->animals, fn (TransferOrderAnimalEntity $a) => $a->isPending()));
    }

    private function countByStatus(TransferOrderAnimalStatus $status): int
    {
        return count(array_filter($this->animals, fn (TransferOrderAnimalEntity $a) => $a->getStatus() === $status));
    }

    private function requireReason(string $reason, string $action): void
    {
        if (trim($reason) === '') {
            throw TransferOrderDomainException::reasonRequired($action);
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

    public function getSourceBatchId(): int
    {
        return $this->sourceBatchId;
    }

    public function getDestinationActivityId(): int
    {
        return $this->destinationActivityId;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getKind(): TransferOrderKind
    {
        return $this->kind;
    }

    public function getCategoryMode(): TransferOrderCategoryMode
    {
        return $this->categoryMode;
    }

    public function getStatus(): TransferOrderStatus
    {
        return $this->status;
    }

    public function getDestinationMode(): string
    {
        return $this->destinationMode;
    }

    public function getPlannedHeadCount(): int
    {
        return $this->plannedHeadCount;
    }

    public function getMovementDate(): string
    {
        return $this->movementDate;
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
     * @return TransferOrderDestinationEntity[]
     */
    public function getDestinations(): array
    {
        return $this->destinations;
    }

    /**
     * @return TransferOrderAnimalEntity[]
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

    public function getSourceBatchName(): ?string
    {
        return $this->sourceBatchName;
    }

    public function getSourceActivityName(): ?string
    {
        return $this->sourceActivityName;
    }

    public function getDestinationActivityName(): ?string
    {
        return $this->destinationActivityName;
    }

    public function getRequestedByUserName(): ?string
    {
        return $this->requestedByUserName;
    }
}
