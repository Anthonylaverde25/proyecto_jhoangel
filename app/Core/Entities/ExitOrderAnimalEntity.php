<?php

declare(strict_types=1);

namespace App\Core\Entities;

use App\Core\Enums\ExitOrderAnimalStatus;
use App\Core\Exceptions\ExitOrderDomainException;
use App\Core\ValueObjects\ExitSnapshot;

/**
 * One animal of an exit order. Once it leaves, the line keeps where it was (batch, activity,
 * category) so the breakdown of what left never moves, and what was agreed for it (E7).
 */
final class ExitOrderAnimalEntity
{
    public function __construct(
        private readonly ?int $id,
        private readonly int $caravanId,
        private ExitOrderAnimalStatus $status = ExitOrderAnimalStatus::PENDING,
        private ?ExitSnapshot $snapshot = null,
        private ?float $weightKg = null,
        private ?float $pricePerKg = null,
        private ?float $amount = null,
        private ?string $exitedAt = null,
        private ?int $caravanMovementId = null,
        private ?int $caravanWeightId = null,
        private ?string $skippedReason = null,
        private readonly ?string $label = null,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCaravanId(): int
    {
        return $this->caravanId;
    }

    public function getStatus(): ExitOrderAnimalStatus
    {
        return $this->status;
    }

    public function isPending(): bool
    {
        return $this->status === ExitOrderAnimalStatus::PENDING;
    }

    public function getSnapshot(): ?ExitSnapshot
    {
        return $this->snapshot;
    }

    public function getWeightKg(): ?float
    {
        return $this->weightKg;
    }

    public function getPricePerKg(): ?float
    {
        return $this->pricePerKg;
    }

    public function getAmount(): ?float
    {
        return $this->amount;
    }

    public function getExitedAt(): ?string
    {
        return $this->exitedAt;
    }

    public function getCaravanMovementId(): ?int
    {
        return $this->caravanMovementId;
    }

    public function getCaravanWeightId(): ?int
    {
        return $this->caravanWeightId;
    }

    public function getSkippedReason(): ?string
    {
        return $this->skippedReason;
    }

    /** The ear tag, or the calf named by its mother; for screens only. */
    public function getLabel(): ?string
    {
        return $this->label;
    }

    /**
     * The animal left. E7: with weight and price per kg the amount is their product, rounded to
     * cents; without one of them an amount typed directly (a per-head sale) is kept, or none.
     */
    public function markExited(
        string $exitedAt,
        ExitSnapshot $snapshot,
        ?float $weightKg,
        ?float $pricePerKg,
        ?float $amount,
        int $caravanMovementId,
        ?int $caravanWeightId,
    ): void {
        if (!$this->isPending()) {
            throw ExitOrderDomainException::on('caravan_id', 'Ese animal ya no está pendiente en la orden.');
        }

        $this->status = ExitOrderAnimalStatus::EXITED;
        $this->exitedAt = $exitedAt;
        $this->snapshot = $snapshot;
        $this->weightKg = $weightKg;
        $this->pricePerKg = $pricePerKg;
        $this->amount = $weightKg !== null && $pricePerKg !== null
            ? round($weightKg * $pricePerKg, 2)
            : $amount;
        $this->caravanMovementId = $caravanMovementId;
        $this->caravanWeightId = $caravanWeightId;
    }

    /** Taken off the roll without leaving: the order closed with it pending, or it died. */
    public function skip(string $reason): void
    {
        if (!$this->isPending()) {
            throw ExitOrderDomainException::on('caravan_id', 'Ese animal ya no está pendiente en la orden.');
        }

        $this->status = ExitOrderAnimalStatus::SKIPPED;
        $this->skippedReason = $reason;
    }
}
