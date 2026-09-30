<?php

declare(strict_types=1);

namespace App\Core\Entities;

use App\Core\Enums\TransferOrderCategoryMode;
use App\Core\Enums\TransferOrderKind;
use App\Core\Enums\TransferOrderStatus;
use App\Core\Enums\WeaningOrderAnimalStatus;
use App\Core\Enums\WeaningType;
use App\Core\Exceptions\WeaningOrderDomainException;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * A weaning order: which calves are taken off their mothers, into which weaning batches and with
 * which category, committed before the chute (PLANNED) or registered after it (REGISTERED).
 *
 * It shares the lifecycle, the kind and the category mode of a transfer order, and their codes.
 * What it does not share is the single source batch: the calves of one order may come from
 * several breeding batches, so the source lives on each line.
 *
 * The status is never set by hand. It is recalculated against the roll: an order with no PENDING
 * line left is EXECUTED, one with some is PARTIAL. What weans the calves is the DEST-01
 * processing; the status is its consequence, never its cause.
 */
final class WeaningOrderEntity
{
    public const MODE_SINGLE = 'single';
    public const MODE_PER_ANIMAL = 'per_animal';

    /**
     * Whether the destinations and the roll were rewritten since the order was loaded, so the
     * repository replaces them instead of only updating statuses.
     */
    private bool $rosterReplaced = false;

    /**
     * @param WeaningOrderDestinationEntity[] $destinations
     * @param WeaningOrderAnimalEntity[] $animals
     * @param TransferOrderHistoryEntity[] $history the same history line as a transfer order's
     */
    public function __construct(
        private readonly ?int $id,
        private readonly int $companyId,
        private readonly string $code,
        private TransferOrderStatus $status,
        private readonly TransferOrderKind $kind,
        private string $destinationMode,
        private TransferOrderCategoryMode $categoryMode,
        private readonly int $destinationActivityId,
        private ?WeaningType $weaningType,
        private int $plannedHeadCount,
        private string $weaningDate,
        private readonly ?int $requestedByUserId,
        private ?DateTimeInterface $emittedAt,
        private ?DateTimeInterface $printedAt = null,
        private ?DateTimeInterface $firstExecutedAt = null,
        private ?DateTimeInterface $closedAt = null,
        private ?string $responsable = null,
        private ?string $observations = null,
        private ?string $closingReason = null,
        private array $destinations = [],
        private array $animals = [],
        private readonly array $history = [],
        private readonly ?DateTimeInterface $createdAt = null,
        private readonly ?string $destinationActivityName = null,
        private readonly ?string $requestedByUserName = null
    ) {
    }

    /**
     * Creates the order, as a draft or already issued.
     *
     * @param WeaningOrderDestinationEntity[] $destinations
     * @param WeaningOrderAnimalEntity[] $animals
     *
     * @throws WeaningOrderDomainException
     */
    public static function create(
        bool $issued,
        int $companyId,
        string $code,
        TransferOrderKind $kind,
        string $destinationMode,
        TransferOrderCategoryMode $categoryMode,
        int $destinationActivityId,
        ?WeaningType $weaningType,
        string $weaningDate,
        ?int $requestedByUserId,
        ?string $responsable,
        ?string $observations,
        array $destinations,
        array $animals
    ): self {
        self::assertRoster($destinationMode, $destinations, $animals);
        self::assertCategoryTargets($categoryMode, $animals);

        return new self(
            id: null,
            companyId: $companyId,
            code: $code,
            status: $issued ? TransferOrderStatus::ISSUED : TransferOrderStatus::DRAFT,
            kind: $kind,
            destinationMode: $destinationMode,
            categoryMode: $categoryMode,
            destinationActivityId: $destinationActivityId,
            weaningType: $weaningType,
            plannedHeadCount: count($animals),
            weaningDate: $weaningDate,
            requestedByUserId: $requestedByUserId,
            emittedAt: $issued ? new DateTimeImmutable() : null,
            responsable: $responsable,
            observations: $observations,
            destinations: $destinations,
            animals: $animals
        );
    }

    /**
     * Rewrites a draft: other destinations, other calves, other modes or header.
     *
     * @param WeaningOrderDestinationEntity[] $destinations
     * @param WeaningOrderAnimalEntity[] $animals
     *
     * @throws WeaningOrderDomainException
     */
    public function updateDraft(
        string $destinationMode,
        TransferOrderCategoryMode $categoryMode,
        ?WeaningType $weaningType,
        string $weaningDate,
        ?string $responsable,
        ?string $observations,
        array $destinations,
        array $animals
    ): void {
        if (!$this->status->isEditable()) {
            throw WeaningOrderDomainException::domainError(
                "La orden {$this->code} está {$this->status->label()}: sólo un borrador se puede modificar.",
                'NOT_EDITABLE'
            );
        }

        self::assertRoster($destinationMode, $destinations, $animals);
        self::assertCategoryTargets($categoryMode, $animals);

        $this->destinationMode = $destinationMode;
        $this->categoryMode = $categoryMode;
        $this->weaningType = $weaningType;
        $this->weaningDate = $weaningDate;
        $this->responsable = $responsable;
        $this->observations = $observations;
        $this->destinations = $destinations;
        $this->animals = $animals;
        $this->plannedHeadCount = count($animals);
        $this->rosterReplaced = true;
    }

    /**
     * Draft → issued. From here the order commits its calves and can be printed and executed.
     *
     * @throws WeaningOrderDomainException
     */
    public function issue(): void
    {
        if ($this->status !== TransferOrderStatus::DRAFT) {
            throw WeaningOrderDomainException::invalidStateTransition($this->status->label(), TransferOrderStatus::ISSUED->label());
        }

        $this->status = TransferOrderStatus::ISSUED;
        $this->emittedAt = new DateTimeImmutable();
        $this->plannedHeadCount = count($this->animals);
    }

    /**
     * The type marked at the chute, for an order that did not declare one. What the order already
     * declared is never overwritten by the paper.
     */
    public function declareWeaningTypeIfMissing(?WeaningType $type): void
    {
        if ($this->weaningType === null && $type !== null) {
            $this->weaningType = $type;
        }
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
     * Registers one execution, from the screen, from a registration or from a scanned sheet.
     *
     * @param array<int, int> $movementIdByCaravanId the WEANING movement that fulfilled each line
     * @param array<string, array{id: int, name: string}> $batchByDestinationKey the batch each destination landed in
     * @param array<int, array{0: int, 1: ?int}> $categoryByCaravanId the C/S each calf changed to, if any; an
     *        order that left the category for the chute keeps it on its line
     *
     * @throws WeaningOrderDomainException
     */
    public function recordExecution(
        array $movementIdByCaravanId,
        array $batchByDestinationKey,
        DateTimeInterface $at,
        array $categoryByCaravanId = []
    ): void {
        if (!$this->status->isOpen()) {
            throw WeaningOrderDomainException::domainError(
                "La orden {$this->code} está {$this->status->label()} y no admite más destetes.",
                'WEANING_ORDER_NOT_EXECUTABLE'
            );
        }

        foreach ($this->animals as $animal) {
            $movementId = $movementIdByCaravanId[$animal->getCaravanId()] ?? null;

            if ($movementId !== null && $animal->isPending()) {
                $animal->markWeaned(
                    $movementId,
                    $at,
                    $this->categoryMode === TransferOrderCategoryMode::AT_CHUTE ? ($categoryByCaravanId[$animal->getCaravanId()] ?? null) : null
                );
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
     * Done, knowing some calves were not weaned with it. Only from PARTIAL: an order nothing was
     * executed against is cancelled, not closed.
     *
     * @throws WeaningOrderDomainException
     */
    public function closeIncomplete(string $reason): void
    {
        if ($this->status !== TransferOrderStatus::PARTIAL) {
            throw WeaningOrderDomainException::invalidStateTransition($this->status->label(), TransferOrderStatus::CLOSED_INCOMPLETE->label());
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
     * It did not happen, or it is being rebuilt. Never from PARTIAL: with calves already weaned,
     * cancelling would erase a fact. Discarding a draft needs no reason; cancelling an issued
     * order does.
     *
     * @throws WeaningOrderDomainException
     */
    public function cancel(?string $reason): void
    {
        if ($this->status !== TransferOrderStatus::ISSUED && $this->status !== TransferOrderStatus::DRAFT) {
            throw WeaningOrderDomainException::invalidStateTransition($this->status->label(), TransferOrderStatus::CANCELLED->label());
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

    public function destinationByKey(string $key): ?WeaningOrderDestinationEntity
    {
        foreach ($this->destinations as $destination) {
            if ($destination->getKey() === $key) {
                return $destination;
            }
        }

        return null;
    }

    public function animalByCaravanId(int $caravanId): ?WeaningOrderAnimalEntity
    {
        foreach ($this->animals as $animal) {
            if ($animal->getCaravanId() === $caravanId) {
                return $animal;
            }
        }

        return null;
    }

    public function weanedCount(): int
    {
        return $this->countByStatus(WeaningOrderAnimalStatus::WEANED);
    }

    public function pendingCount(): int
    {
        return $this->countByStatus(WeaningOrderAnimalStatus::PENDING);
    }

    public function skippedCount(): int
    {
        return $this->countByStatus(WeaningOrderAnimalStatus::SKIPPED);
    }

    /**
     * @return WeaningOrderAnimalEntity[]
     */
    public function pendingAnimals(): array
    {
        return array_values(array_filter($this->animals, fn (WeaningOrderAnimalEntity $a) => $a->isPending()));
    }

    /**
     * The breeding batches the calves come from, by id, with their names when known.
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
     * @param WeaningOrderDestinationEntity[] $destinations
     * @param WeaningOrderAnimalEntity[] $animals
     *
     * @throws WeaningOrderDomainException
     */
    private static function assertRoster(string $destinationMode, array $destinations, array $animals): void
    {
        if ($animals === []) {
            throw WeaningOrderDomainException::domainError('Una orden de destete necesita al menos una cría.', 'EMPTY_ROLL');
        }

        if (!in_array($destinationMode, [self::MODE_SINGLE, self::MODE_PER_ANIMAL], true)) {
            throw WeaningOrderDomainException::domainError("Modo de destino desconocido: {$destinationMode}.", 'INVALID_DESTINATION_MODE');
        }

        if ($destinationMode === self::MODE_SINGLE && count($destinations) !== 1) {
            throw WeaningOrderDomainException::domainError(
                'Con destino único todas las crías van a un mismo lote de destete: declarálo.',
                'DESTINATION_MISSING'
            );
        }

        $keys = [];
        foreach ($destinations as $destination) {
            $keys[$destination->getKey()] = true;
        }

        foreach ($animals as $animal) {
            $key = $animal->getDestinationKey();

            if ($key !== null && !isset($keys[$key])) {
                throw WeaningOrderDomainException::domainError("Una cría apunta a un lote de destete que la orden no declara ({$key}).", 'UNKNOWN_DESTINATION');
            }

            if ($key === null && $destinationMode === self::MODE_SINGLE) {
                throw WeaningOrderDomainException::domainError('Con destino único todas las crías van al mismo lote.', 'DESTINATION_MISSING');
            }
        }
    }

    /**
     * Only a DECLARED order carries target categories, and it carries at least one. In the other
     * two modes a target would be printed nowhere and applied silently, so it is refused.
     *
     * @param WeaningOrderAnimalEntity[] $animals
     *
     * @throws WeaningOrderDomainException
     */
    private static function assertCategoryTargets(TransferOrderCategoryMode $categoryMode, array $animals): void
    {
        $withTarget = array_filter($animals, fn (WeaningOrderAnimalEntity $a) => $a->hasTargetCategory());

        if ($categoryMode === TransferOrderCategoryMode::DECLARED && $withTarget === []) {
            throw WeaningOrderDomainException::domainError(
                'La orden dice que la categoría cambia, pero no asigna la nueva categoría a ninguna cría.',
                'CATEGORY_TARGET_MISSING'
            );
        }

        if ($categoryMode !== TransferOrderCategoryMode::DECLARED && $withTarget !== []) {
            throw WeaningOrderDomainException::domainError(
                'Sólo una orden con la categoría declarada puede asignar categorías nuevas a sus crías.',
                'CATEGORY_TARGET_NOT_EXPECTED'
            );
        }
    }

    private function countByStatus(WeaningOrderAnimalStatus $status): int
    {
        return count(array_filter($this->animals, fn (WeaningOrderAnimalEntity $a) => $a->getStatus() === $status));
    }

    private function requireReason(string $reason, string $action): void
    {
        if (trim($reason) === '') {
            throw WeaningOrderDomainException::reasonRequired($action);
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

    public function getDestinationMode(): string
    {
        return $this->destinationMode;
    }

    public function getCategoryMode(): TransferOrderCategoryMode
    {
        return $this->categoryMode;
    }

    public function getDestinationActivityId(): int
    {
        return $this->destinationActivityId;
    }

    public function getWeaningType(): ?WeaningType
    {
        return $this->weaningType;
    }

    public function getPlannedHeadCount(): int
    {
        return $this->plannedHeadCount;
    }

    public function getWeaningDate(): string
    {
        return $this->weaningDate;
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
     * @return WeaningOrderDestinationEntity[]
     */
    public function getDestinations(): array
    {
        return $this->destinations;
    }

    /**
     * @return WeaningOrderAnimalEntity[]
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

    public function getDestinationActivityName(): ?string
    {
        return $this->destinationActivityName;
    }

    public function getRequestedByUserName(): ?string
    {
        return $this->requestedByUserName;
    }
}
