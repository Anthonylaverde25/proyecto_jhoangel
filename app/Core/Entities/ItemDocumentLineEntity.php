<?php

declare(strict_types=1);

namespace App\Core\Entities;

/**
 * A line of a stock operation: always a stock-tracked article. Its cost is known only once the
 * ledger holds the balance row locked (the average of that moment), so valuate() comes from there.
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
    ) {
    }

    public function valuate(string $unitCost, string $totalCost): void
    {
        $this->unitCost = $unitCost;
        $this->totalCost = $totalCost;
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
    public function isValuated(): bool { return $this->unitCost !== null; }
    public function getFiscalDocumentLineId(): ?int { return $this->fiscalDocumentLineId; }
    public function getCountedQuantity(): ?string { return $this->countedQuantity; }
    public function getReversesLineId(): ?int { return $this->reversesLineId; }
    public function getMovement(): ?StockMovementEntity { return $this->movement; }
    public function getId(): ?int { return $this->id; }
    public function getItemName(): ?string { return $this->itemName; }
    public function getItemUnitSymbol(): ?string { return $this->itemUnitSymbol; }
    public function getInputLabel(): ?string { return $this->inputLabel; }
}
