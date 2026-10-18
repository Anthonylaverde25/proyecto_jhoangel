<?php

declare(strict_types=1);

namespace App\Core\Entities;

use App\Core\Exceptions\ItemDocumentException;

/**
 * Materialised balance and weighted average cost of an item in a warehouse. Quantity, average and
 * last movement change only by applying a movement (S2); the critical level is the only value the
 * operator edits.
 */
final class ItemStockEntity
{
    public function __construct(
        private readonly int $id,
        private readonly int $companyId,
        private readonly int $warehouseId,
        private readonly int $itemId,
        private string $quantity,
        private string $averageUnitCost,
        private ?string $criticalStock,
        private ?int $lastMovementId,
        private readonly ?string $warehouseName = null,
    ) {
    }

    /** S3: the movement belongs to this row; its "after" values become the balance. */
    public function apply(StockMovementEntity $movement): void
    {
        if ($movement->warehouseId !== $this->warehouseId || $movement->itemId !== $this->itemId) {
            throw new \LogicException('The movement belongs to another balance row.');
        }
        $this->quantity = $movement->balanceAfter;
        $this->averageUnitCost = $movement->averageCostAfter;
        $this->lastMovementId = $movement->id;
    }

    public function setCriticalStock(?string $level): void
    {
        if ($level !== null && bccomp($level, '0', 4) < 0) {
            throw ItemDocumentException::on('critical_stock', 'El nivel crítico no puede ser negativo.');
        }
        $this->criticalStock = $level;
    }

    /** Average times balance; a negative balance is worth nothing until it is counted. */
    public function value(): string
    {
        return bccomp($this->quantity, '0', 4) > 0 ? bcmul($this->quantity, $this->averageUnitCost, 4) : '0.0000';
    }

    public function isBelowCritical(): bool
    {
        return $this->criticalStock !== null && bccomp($this->quantity, $this->criticalStock, 4) <= 0;
    }

    public function getId(): int { return $this->id; }
    public function getCompanyId(): int { return $this->companyId; }
    public function getWarehouseId(): int { return $this->warehouseId; }
    public function getItemId(): int { return $this->itemId; }
    public function getQuantity(): string { return $this->quantity; }
    public function getAverageUnitCost(): string { return $this->averageUnitCost; }
    public function getCriticalStock(): ?string { return $this->criticalStock; }
    public function getLastMovementId(): ?int { return $this->lastMovementId; }
    public function getWarehouseName(): ?string { return $this->warehouseName; }
}
