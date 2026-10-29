<?php

declare(strict_types=1);

namespace App\Core\Entities;

use App\Core\Enums\ExitOrderAnimalStatus;
use App\Core\Enums\ExitOrderKind;
use App\Core\Enums\ExitOrderStatus;
use App\Core\Enums\ExitReason;
use App\Core\Exceptions\ExitOrderDomainException;
use App\Core\ValueObjects\ExitSnapshot;

/**
 * An exit order (egreso plan v5, 01): animals leaving the company for a reason (SALE), to a buyer,
 * with their optional DTEs. Issued before the trip and executed in one or several loads, or
 * registered after it happened, already executed. The status follows the roll (01 §3).
 */
final class ExitOrderEntity
{
    private bool $rosterReplaced = false;

    /**
     * @param list<ExitOrderAnimalEntity> $animals
     * @param list<ExitOrderDteEntity> $dtes
     * @param list<ExitOrderHistoryEntity> $history
     */
    public function __construct(
        private readonly ?int $id,
        private readonly int $companyId,
        private readonly string $code,
        private readonly ExitOrderKind $kind,
        private readonly ExitReason $reason,
        private ExitOrderStatus $status,
        private ?int $buyerPartnerId,
        private string $exitDate,
        private int $plannedHeadCount,
        private array $animals,
        private readonly ?int $requestedByUserId = null,
        private readonly ?\DateTimeInterface $emittedAt = null,
        private ?\DateTimeInterface $firstExecutedAt = null,
        private ?\DateTimeInterface $closedAt = null,
        private ?string $responsable = null,
        private ?string $observations = null,
        private ?string $closingReason = null,
        private readonly array $dtes = [],
        private readonly array $history = [],
        private readonly ?string $buyerName = null,
        private readonly ?string $buyerCuit = null,
    ) {
    }

    /**
     * A new order: issued (PLANNED), or born to be executed right away (REGISTERED).
     *
     * @param list<int> $caravanIds
     * @param array<int, string> $labels caravan id => label, for the repeated-animal message
     */
    public static function create(
        int $companyId,
        string $code,
        ExitOrderKind $kind,
        ?int $buyerPartnerId,
        string $exitDate,
        array $caravanIds,
        ?int $requestedByUserId,
        ?string $responsable,
        ?string $observations,
        array $labels = [],
    ): self {
        self::assertRoll($caravanIds, $labels);

        return new self(
            id: null,
            companyId: $companyId,
            code: $code,
            kind: $kind,
            reason: ExitReason::SALE,
            status: ExitOrderStatus::ISSUED,
            buyerPartnerId: $buyerPartnerId,
            exitDate: $exitDate,
            plannedHeadCount: count($caravanIds),
            animals: array_map(fn (int $id) => new ExitOrderAnimalEntity(null, $id), array_values($caravanIds)),
            requestedByUserId: $requestedByUserId,
            emittedAt: new \DateTimeImmutable(),
            responsable: $responsable,
            observations: $observations,
        );
    }

    /**
     * @param list<int> $caravanIds
     * @param array<int, string> $labels
     */
    private static function assertRoll(array $caravanIds, array $labels): void
    {
        if ($caravanIds === []) {
            throw ExitOrderDomainException::emptyRoll();
        }

        $seen = [];
        foreach ($caravanIds as $caravanId) {
            if (isset($seen[$caravanId])) {
                throw ExitOrderDomainException::repeatedAnimal($labels[$caravanId] ?? "El animal {$caravanId}");
            }
            $seen[$caravanId] = true;
        }
    }

    /**
     * Header and roll of an issued order from which nothing left yet.
     *
     * @param list<int> $caravanIds
     * @param array<int, string> $labels
     */
    public function replaceRoll(?int $buyerPartnerId, string $exitDate, array $caravanIds, ?string $responsable, ?string $observations, array $labels = []): void
    {
        if ($this->status !== ExitOrderStatus::ISSUED) {
            throw ExitOrderDomainException::invalidTransition($this->status->label(), 'editar');
        }
        if ($this->exitedCount() > 0 || $this->skippedCount() > 0) {
            throw ExitOrderDomainException::rollFixed();
        }

        self::assertRoll($caravanIds, $labels);

        $this->buyerPartnerId = $buyerPartnerId;
        $this->exitDate = $exitDate;
        $this->responsable = $responsable;
        $this->observations = $observations;
        $this->plannedHeadCount = count($caravanIds);
        $this->animals = array_map(fn (int $id) => new ExitOrderAnimalEntity(null, $id), array_values($caravanIds));
        $this->rosterReplaced = true;
    }

    /**
     * The $/kg agreed with the buyer for animals still pending, by caravan id. A reference the load
     * starts from; the price that counts is the one the exit declares.
     *
     * @param array<int, float> $prices
     */
    public function agreePrices(array $prices): void
    {
        foreach ($this->animals as $line) {
            if (isset($prices[$line->getCaravanId()])) {
                $line->agreePrice($prices[$line->getCaravanId()]);
            }
        }
    }

    /** The line of a pending animal, for a load to execute. */
    public function pendingLine(int $caravanId): ?ExitOrderAnimalEntity
    {
        foreach ($this->animals as $animal) {
            if ($animal->getCaravanId() === $caravanId && $animal->isPending()) {
                return $animal;
            }
        }

        return null;
    }

    public function hasAnimal(int $caravanId): bool
    {
        foreach ($this->animals as $animal) {
            if ($animal->getCaravanId() === $caravanId) {
                return true;
            }
        }

        return false;
    }

    /**
     * A calf that leaves with its mother (P1) joins the roll at execution, pending, and leaves in
     * the same load.
     */
    public function addCalfOfMother(int $calfCaravanId): ExitOrderAnimalEntity
    {
        $line = new ExitOrderAnimalEntity(null, $calfCaravanId);
        $this->animals[] = $line;

        return $line;
    }

    /**
     * One load of the order left on that date.
     *
     * @param array<int, array{snapshot: ExitSnapshot, weight_kg: ?float, weight_estimated: bool, price_per_kg: ?float, amount: ?float, movement_id: int, weight_id: ?int}> $exits by caravan id
     */
    public function recordExits(string $exitDate, array $exits): void
    {
        if (!$this->status->isOpen()) {
            throw ExitOrderDomainException::invalidTransition($this->status->label(), 'ejecutar');
        }

        foreach ($exits as $caravanId => $exit) {
            $line = $this->pendingLine($caravanId)
                ?? throw ExitOrderDomainException::notInRoll('caravan_id', "El animal {$caravanId}");

            $line->markExited(
                $exitDate,
                $exit['snapshot'],
                $exit['weight_kg'],
                $exit['price_per_kg'],
                $exit['amount'],
                $exit['movement_id'],
                $exit['weight_id'],
                $exit['weight_estimated'] ?? false,
            );
        }

        $this->firstExecutedAt ??= new \DateTimeImmutable();
        if ($this->kind === ExitOrderKind::REGISTERED) {
            $this->exitDate = $exitDate;
        }
        $this->deriveStatus();
    }

    /**
     * A pending animal died (01 §5.1): it leaves the roll with the reason, and when it was the last
     * one pending the order closes on its own.
     */
    public function skipByDeath(int $caravanId, string $date, string $causeName, string $label): void
    {
        if (!$this->status->isOpen()) {
            throw ExitOrderDomainException::invalidTransition($this->status->label(), 'modificar');
        }

        $line = $this->pendingLine($caravanId)
            ?? throw ExitOrderDomainException::notInRoll('caravan_id', $label);

        $formatted = \DateTimeImmutable::createFromFormat('Y-m-d', $date)?->format('d/m') ?? $date;
        $line->skip("Murió el {$formatted} · {$causeName}");

        $this->deriveStatus("Baja por muerte: {$label}");
    }

    /** Closes a partly executed order: what is still pending did not leave. */
    public function closeIncomplete(string $reason): void
    {
        if ($this->status !== ExitOrderStatus::PARTIAL) {
            throw ExitOrderDomainException::invalidTransition($this->status->label(), 'cerrar incompleta');
        }
        if (trim($reason) === '') {
            throw ExitOrderDomainException::reasonRequired('closing_reason');
        }

        foreach ($this->animals as $animal) {
            if ($animal->isPending()) {
                $animal->skip($reason);
            }
        }

        $this->closingReason = $reason;
        $this->deriveStatus();
    }

    /** Cancels an issued order from which nothing left. */
    public function cancel(string $reason): void
    {
        if ($this->status !== ExitOrderStatus::ISSUED || $this->exitedCount() > 0) {
            throw ExitOrderDomainException::invalidTransition($this->status->label(), 'anular');
        }
        if (trim($reason) === '') {
            throw ExitOrderDomainException::reasonRequired();
        }

        $this->status = ExitOrderStatus::CANCELLED;
        $this->closingReason = $reason;
        $this->closedAt = new \DateTimeImmutable();
    }

    /** A DTE can be loaded in any status but cancelled, also once the order is closed. */
    public function assertAcceptsDte(): void
    {
        if ($this->status === ExitOrderStatus::CANCELLED) {
            throw ExitOrderDomainException::dteOnCancelled();
        }
    }

    /**
     * With nothing pending the order is over: EXECUTED when every line left, CLOSED_INCOMPLETE when
     * any did not. Something left and something pending is PARTIAL.
     */
    private function deriveStatus(?string $automaticClosingReason = null): void
    {
        $pending = $this->pendingCount();

        if ($pending > 0) {
            $this->status = $this->exitedCount() > 0 ? ExitOrderStatus::PARTIAL : ExitOrderStatus::ISSUED;

            return;
        }

        if ($this->skippedCount() > 0) {
            $this->status = ExitOrderStatus::CLOSED_INCOMPLETE;
            // Closed by itself (a death, or the last load after one): the reasons of what did not leave.
            $this->closingReason ??= $automaticClosingReason ?? implode('; ', array_unique(array_filter(array_map(
                fn (ExitOrderAnimalEntity $a) => $a->getSkippedReason(),
                $this->animals
            ))));
        } else {
            $this->status = ExitOrderStatus::EXECUTED;
        }

        $this->closedAt = new \DateTimeImmutable();
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

    public function getKind(): ExitOrderKind
    {
        return $this->kind;
    }

    public function getReason(): ExitReason
    {
        return $this->reason;
    }

    public function getStatus(): ExitOrderStatus
    {
        return $this->status;
    }

    public function getBuyerPartnerId(): ?int
    {
        return $this->buyerPartnerId;
    }

    public function getBuyerName(): ?string
    {
        return $this->buyerName;
    }

    public function getBuyerCuit(): ?string
    {
        return $this->buyerCuit;
    }

    public function getExitDate(): string
    {
        return $this->exitDate;
    }

    public function getPlannedHeadCount(): int
    {
        return $this->plannedHeadCount;
    }

    /** @return list<ExitOrderAnimalEntity> */
    public function getAnimals(): array
    {
        return $this->animals;
    }

    /** @return list<ExitOrderDteEntity> */
    public function getDtes(): array
    {
        return $this->dtes;
    }

    /** @return list<ExitOrderHistoryEntity> */
    public function getHistory(): array
    {
        return $this->history;
    }

    public function getRequestedByUserId(): ?int
    {
        return $this->requestedByUserId;
    }

    public function getEmittedAt(): ?\DateTimeInterface
    {
        return $this->emittedAt;
    }

    public function getFirstExecutedAt(): ?\DateTimeInterface
    {
        return $this->firstExecutedAt;
    }

    public function getClosedAt(): ?\DateTimeInterface
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

    public function isRosterReplaced(): bool
    {
        return $this->rosterReplaced;
    }

    public function exitedCount(): int
    {
        return $this->countLines(ExitOrderAnimalStatus::EXITED);
    }

    public function pendingCount(): int
    {
        return $this->countLines(ExitOrderAnimalStatus::PENDING);
    }

    public function skippedCount(): int
    {
        return $this->countLines(ExitOrderAnimalStatus::SKIPPED);
    }

    public function dteHeadCount(): int
    {
        return array_sum(array_map(fn (ExitOrderDteEntity $dte) => $dte->headCount, $this->dtes));
    }

    /** Animals already left and no DTE loaded: the "Sin DTE" mark (01 §6). */
    public function isWithoutDte(): bool
    {
        return $this->exitedCount() > 0 && $this->dtes === [];
    }

    public function totalWeightKg(): float
    {
        return round(array_sum(array_map(fn (ExitOrderAnimalEntity $a) => $a->getWeightKg() ?? 0.0, $this->exitedLines())), 2);
    }

    public function totalAmount(): float
    {
        return round(array_sum(array_map(fn (ExitOrderAnimalEntity $a) => $a->getAmount() ?? 0.0, $this->exitedLines())), 2);
    }

    /** @return list<ExitOrderAnimalEntity> */
    private function exitedLines(): array
    {
        return array_values(array_filter($this->animals, fn (ExitOrderAnimalEntity $a) => $a->getStatus() === ExitOrderAnimalStatus::EXITED));
    }

    private function countLines(ExitOrderAnimalStatus $status): int
    {
        return count(array_filter($this->animals, fn (ExitOrderAnimalEntity $a) => $a->getStatus() === $status));
    }
}
