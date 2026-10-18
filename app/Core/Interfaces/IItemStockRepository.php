<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

use App\Core\Entities\ItemStockEntity;
use App\Core\Entities\StockMovementEntity;
use App\Core\ValueObjects\KardexFilters;

/**
 * Balances and movements. lockForMovement and applyMovement run inside the caller's transaction.
 */
interface IItemStockRepository
{
    /** Creates the row when missing and locks it (SELECT ... FOR UPDATE), DS7. */
    public function lockForMovement(int $warehouseId, int $itemId, int $companyId): ItemStockEntity;

    /** Appends the movement and saves the balance it produced; returns the movement with its id. */
    public function applyMovement(ItemStockEntity $stock, StockMovementEntity $movement, int $companyId): StockMovementEntity;

    /** @return list<ItemStockEntity> one per warehouse that ever held the item */
    public function findByItem(int $itemId, int $companyId): array;

    public function saveCriticalStock(ItemStockEntity $stock): void;

    /**
     * Totals for the item list, keyed by item id.
     *
     * @param list<int> $itemIds
     * @return array<int, array{quantity: string, value: string, has_negative: bool, has_critical: bool}>
     */
    public function summaries(array $itemIds, int $companyId): array;

    /** @return array{data: list<array<string, mixed>>, total: int} newest first */
    public function kardex(int $itemId, KardexFilters $filters, int $companyId): array;

    /**
     * Rows whose balance or average disagree with their movements (I7).
     *
     * @return list<array<string, mixed>>
     */
    public function reconcile(): array;
}
