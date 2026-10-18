<?php

declare(strict_types=1);

namespace App\Core\Entities;

use App\Core\Enums\UnvaluedLotStatus;
use App\Core\ValueObjects\Decimal;

/**
 * What a line of an unvalued delivery note brought in (delivery C, model 2.2): how much of it is still
 * in the warehouse without cost, and the cost its invoice gave it. remaining only moves while PENDING.
 */
final class UnvaluedLotEntity
{
    public function __construct(
        private ?int $id,
        private readonly int $companyId,
        private readonly int $warehouseId,
        private readonly int $itemId,
        private readonly int $deliveryNoteLineId,
        private readonly int $receiptLineId,
        private readonly string $quantity,
        private string $remainingQuantity,
        private UnvaluedLotStatus $status = UnvaluedLotStatus::PENDING,
        private ?string $valuedUnitCost = null,
        private ?string $valuedQuantity = null,
        private ?int $valuedByLineId = null,
        private ?int $valuationLineId = null,
        private ?string $valuedAt = null,
    ) {
    }

    /** Takes up to $wanted units, oldest lot first (model 2.4); returns what it gave. */
    public function take(Decimal $wanted): Decimal
    {
        $remaining = Decimal::of($this->remainingQuantity);
        $taken = $wanted->compare($remaining) < 0 ? $wanted : $remaining;
        $this->remainingQuantity = $remaining->sub($taken)->toScale(4);

        return $taken;
    }

    /** A reversed outbound gives its units back while the lot still waits for its invoice. */
    public function giveBack(Decimal $quantity): void
    {
        $this->remainingQuantity = Decimal::of($this->remainingQuantity)->add($quantity)->toScale(4);
    }

    /** The invoice brought its cost: what is still in stock joins the average (model 2.3). */
    public function value(string $unitCost, int $byLineId, string $at): void
    {
        $this->valuedUnitCost = $unitCost;
        $this->valuedQuantity = $this->remainingQuantity;
        $this->valuedByLineId = $byLineId;
        $this->valuedAt = $at;
        $this->remainingQuantity = '0.0000';
        $this->status = UnvaluedLotStatus::VALUED;
    }

    public function attachValuationLine(int $lineId): void
    {
        $this->valuationLineId = $lineId;
    }

    /** Its invoice was voided: the units it valued wait again (model 2.8). */
    public function unvalue(): void
    {
        $this->remainingQuantity = (string) $this->valuedQuantity;
        $this->valuedUnitCost = null;
        $this->valuedQuantity = null;
        $this->valuedByLineId = null;
        $this->valuationLineId = null;
        $this->valuedAt = null;
        $this->status = UnvaluedLotStatus::PENDING;
    }

    public function void(): void
    {
        $this->remainingQuantity = '0.0000';
        $this->status = UnvaluedLotStatus::VOIDED;
    }

    /** Some of its units already left the warehouse (Q1). */
    public function wasIssued(): bool
    {
        return Decimal::of($this->remainingQuantity)->compare(Decimal::of($this->quantity)) < 0;
    }

    public function issuedQuantity(): string
    {
        return Decimal::of($this->quantity)->sub(Decimal::of($this->remainingQuantity))->toScale(4);
    }

    public function assignId(int $id): void { $this->id = $id; }
    public function getId(): ?int { return $this->id; }
    public function getCompanyId(): int { return $this->companyId; }
    public function getWarehouseId(): int { return $this->warehouseId; }
    public function getItemId(): int { return $this->itemId; }
    public function getDeliveryNoteLineId(): int { return $this->deliveryNoteLineId; }
    public function getReceiptLineId(): int { return $this->receiptLineId; }
    public function getQuantity(): string { return $this->quantity; }
    public function getRemainingQuantity(): string { return $this->remainingQuantity; }
    public function getStatus(): UnvaluedLotStatus { return $this->status; }
    public function isPending(): bool { return $this->status === UnvaluedLotStatus::PENDING; }
    public function isValued(): bool { return $this->status === UnvaluedLotStatus::VALUED; }
    public function getValuedUnitCost(): ?string { return $this->valuedUnitCost; }
    public function getValuedQuantity(): ?string { return $this->valuedQuantity; }
    public function getValuedByLineId(): ?int { return $this->valuedByLineId; }
    public function getValuationLineId(): ?int { return $this->valuationLineId; }
    public function getValuedAt(): ?string { return $this->valuedAt; }
}
