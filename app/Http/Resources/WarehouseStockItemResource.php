<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Core\Entities\ItemStockEntity;

/**
 * One item held by a warehouse, as GET /warehouses/{id}/stock answers it.
 */
final class WarehouseStockItemResource
{
    /**
     * @param array{stock: ItemStockEntity, code: string, name: string, unit_symbol: string|null} $row
     * @return array<string, mixed>
     */
    public static function fromRow(array $row): array
    {
        $stock = $row['stock'];

        return [
            'item_id' => $stock->getItemId(),
            'code' => $row['code'],
            'name' => $row['name'],
            'unit_symbol' => $row['unit_symbol'],
            'quantity' => $stock->getQuantity(),
            // Delivery C: units waiting for their invoice are in the balance, not in the value.
            'unvalued_quantity' => $stock->getUnvaluedQuantity(),
            'average_unit_cost' => $stock->getAverageUnitCost(),
            'value' => $stock->value(),
            'critical_stock' => $stock->getCriticalStock(),
            'is_negative' => bccomp($stock->getQuantity(), '0', 4) < 0,
            'is_below_critical' => $stock->isBelowCritical(),
        ];
    }
}
