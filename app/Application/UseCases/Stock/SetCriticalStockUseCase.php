<?php

declare(strict_types=1);

namespace App\Application\UseCases\Stock;

use App\Application\Services\Stock\StockIncidentRaiser;
use App\Core\Exceptions\ItemDocumentException;
use App\Core\Interfaces\IItemRepository;
use App\Core\Interfaces\IItemStockRepository;
use App\Core\Interfaces\IWarehouseRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Sets (or clears) the critical level of an item in a warehouse and re-evaluates the critical-stock
 * notice against the current balance, with no movement (D19, H3).
 */
final class SetCriticalStockUseCase
{
    public function __construct(
        private readonly IItemRepository $items,
        private readonly IWarehouseRepository $warehouses,
        private readonly IItemStockRepository $stocks,
        private readonly StockIncidentRaiser $incidents,
        private readonly GetItemStockUseCase $view,
    ) {
    }

    public function __invoke(int $itemId, int $warehouseId, ?string $level, int $companyId, ?int $userId): ItemStockView
    {
        $item = $this->items->findById($itemId, $companyId)
            ?? throw new ModelNotFoundException("Item {$itemId} not found.");
        if (!$item->tracksStock()) {
            throw ItemDocumentException::on('critical_stock', "{$item->getName()} no lleva stock.");
        }
        $owned = array_filter($this->warehouses->findAll($companyId), fn ($w) => $w->id === $warehouseId);
        if ($owned === []) {
            throw new ModelNotFoundException("Warehouse {$warehouseId} not found.");
        }

        DB::transaction(function () use ($itemId, $warehouseId, $level, $companyId, $userId) {
            $stock = $this->stocks->lockForMovement($warehouseId, $itemId, $companyId);
            $stock->setCriticalStock($level);
            $this->stocks->saveCriticalStock($stock);
            $this->incidents->afterCriticalLevelChange($stock, $userId, now()->toIso8601String());
        });

        return ($this->view)($itemId, $companyId);
    }
}
