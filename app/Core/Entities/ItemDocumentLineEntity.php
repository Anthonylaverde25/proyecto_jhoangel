<?php

declare(strict_types=1);

namespace App\Core\Entities;

/**
 * A line of a stock operation: always a stock-tracked article. Its cost is known only once the
 * ledger holds the balance row locked (the average of that moment), so valuate() comes from there.
 * unvaluedQuantity is the part without cost yet (delivery C, model 2.4): totalCost is the known part
 * and unitCost is null when nothing is valued.
 */
final class ItemDocumentLineEntity
{
    public function __construct(
        private readonly int $position,
        private readonly int $itemId,
        private readonly string $inputQuantity,
        private readonly ?int $inputUnitId,
        private readonly ?int $inputPresentationId,
        private readonly string $quantity,
        private readonly ?string $inputUnitPrice = null,
        private ?string $unitCost = null,
        private ?string $totalCost = null,
        private readonly ?int $fiscalDocumentLineId = null,
        private readonly ?string $countedQuantity = null,
        private readonly ?int $reversesLineId = null,
        private ?StockMovementEntity $movement = null,
        private readonly ?int $id = null,
        private readonly ?string $itemName = null,
        private readonly ?string $itemUnitSymbol = null,
        private readonly ?string $inputLabel = null,
        private string $unvaluedQuantity = '0',
        private ?string $unabsorbedCost = null,
        private readonly ?int $semenLotId = null,
        private readonly ?string $semenLotCode = null,
    ) {
    }

    public function valuate(?string $unitCost, string $totalCost, string $unvaluedQuantity = '0'): void
    {
        $this->unitCost = $unitCost;
        $this->totalCost = $totalCost;
        $this->unvaluedQuantity = $unvaluedQuantity;
    }

    /** Q3: the share of a valuation that no valued stock could take. */
    public function leaveUnabsorbed(string $amount): void
    {
        $this->unabsorbedCost = $amount;
    }

    public function attachMovement(StockMovementEntity $movement): void
    {
        $this->movement = $movement;
    }

    public function getPosition(): int { return $this->position; }
    public function getItemId(): int { return $this->itemId; }
    public function getInputQuantity(): string { return $this->inputQuantity; }
    public function getInputUnitId(): ?int { return $this->inputUnitId; }
    public function getInputPresentationId(): ?int { return $this->inputPresentationId; }
    public function getQuantity(): string { return $this->quantity; }
    public function getInputUnitPrice(): ?string { return $this->inputUnitPrice; }
    public function getUnitCost(): string { return $this->unitCost ?? '0'; }
    public function getTotalCost(): string { return $this->totalCost ?? '0'; }
    public function getUnitCostOrNull(): ?string { return $this->unitCost; }
    public function isValuated(): bool { return $this->unitCost !== null; }
    public function getUnvaluedQuantity(): string { return $this->unvaluedQuantity; }
    public function getUnabsorbedCost(): ?string { return $this->unabsorbedCost; }
    /** Every unit of it waits for its invoice: it carries no unit cost. */
    public function isFullyUnvalued(): bool
    {
        return bccomp($this->quantity, '0', 4) > 0 && bccomp($this->unvaluedQuantity, $this->quantity, 4) === 0;
    }
    public function getFiscalDocumentLineId(): ?int { return $this->fiscalDocumentLineId; }
    public function getCountedQuantity(): ?string { return $this->countedQuantity; }
    public function getReversesLineId(): ?int { return $this->reversesLineId; }
    public function getMovement(): ?StockMovementEntity { return $this->movement; }
    public function getId(): ?int { return $this->id; }
    public function getItemName(): ?string { return $this->itemName; }
    public function getItemUnitSymbol(): ?string { return $this->itemUnitSymbol; }
    public function getInputLabel(): ?string { return $this->inputLabel; }
    /** Semen S-A: the lot this line moves; NULL for every other item, or for semen with no lot yet (SL6). */
    public function getSemenLotId(): ?int { return $this->semenLotId; }
    public function getSemenLotCode(): ?string { return $this->semenLotCode; }
}
