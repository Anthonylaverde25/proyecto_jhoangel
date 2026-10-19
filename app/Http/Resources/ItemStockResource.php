<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Application\UseCases\Stock\ItemStockView;
use App\Core\Entities\ItemStockEntity;
use App\Core\Entities\StockIncidentEntity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The Stock tab of an item.
 *
 * @property-read ItemStockView $resource
 */
class ItemStockResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $item = $this->resource->item;

        return [
            'item' => ['id' => $item->getId(), 'name' => $item->getName(), 'unit_symbol' => $item->getUnit()?->getSymbol(), 'track_stock' => $item->tracksStock()],
            'warehouses' => array_map(fn (ItemStockEntity $stock) => [
                'warehouse' => [
                    'id' => $stock->getWarehouseId(),
                    'code' => $stock->getWarehouseCode(),
                    'name' => $stock->getWarehouseName(),
                    'is_active' => $stock->isWarehouseActive(),
                ],
                'quantity' => $stock->getQuantity(),
                // Delivery C: units without cost yet; the value is of the valued ones only.
                'unvalued_quantity' => $stock->getUnvaluedQuantity(),
                'valued_quantity' => $stock->valuedQuantity(),
                'average_unit_cost' => $stock->getAverageUnitCost(),
                'value' => $stock->value(),
                'critical_stock' => $stock->getCriticalStock(),
                'is_below_critical' => $stock->isBelowCritical(),
            ], $this->resource->stocks),
            'open_incidents' => array_map(fn (StockIncidentEntity $i) => StockIncidentResource::shape($i), $this->resource->openIncidents),
            'pending_lots' => $this->resource->pendingLots,
        ];
    }
}
