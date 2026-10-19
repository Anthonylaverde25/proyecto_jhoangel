<?php

declare(strict_types=1);

namespace App\Application\UseCases\Warehouses;

use App\Core\Entities\WarehouseEntity;
use App\Core\ValueObjects\WarehouseSummary;

/**
 * The company's warehouses with what each one holds (DD4).
 */
final readonly class WarehouseListing
{
    /**
     * @param list<WarehouseEntity> $warehouses default first
     * @param array<int, WarehouseSummary> $summaries by warehouse id
     */
    public function __construct(
        public array $warehouses,
        public array $summaries,
    ) {
    }

    public function summaryOf(int $warehouseId): WarehouseSummary
    {
        return $this->summaries[$warehouseId] ?? new WarehouseSummary();
    }
}
