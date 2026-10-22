<?php

declare(strict_types=1);

namespace App\Application\UseCases\Warehouses;

use App\Core\Entities\WarehouseEntity;
use App\Core\Interfaces\IItemStockRepository;
use App\Core\Interfaces\IWarehouseRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * One warehouse and the items it holds. Read-only: the detail opened from the assistant.
 */
final class GetWarehouseStockUseCase
{
    public function __construct(
        private readonly IWarehouseRepository $warehouses,
        private readonly IItemStockRepository $stocks,
    ) {
    }

    /** @return array{warehouse: WarehouseEntity, items: list<array<string, mixed>>} */
    public function __invoke(int $warehouseId, int $companyId): array
    {
        $warehouse = $this->warehouses->findById($warehouseId, $companyId)
            ?? throw new ModelNotFoundException("Warehouse {$warehouseId} not found.");

        return ['warehouse' => $warehouse, 'items' => $this->stocks->byWarehouse($warehouseId, $companyId)];
    }
}
