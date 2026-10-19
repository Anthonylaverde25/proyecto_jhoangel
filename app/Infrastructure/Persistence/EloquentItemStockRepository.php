<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Mappers\DecimalColumn;
use App\Application\Mappers\StockMapper;
use App\Core\Entities\FiscalDocumentEntity;
use App\Core\Entities\ItemStockEntity;
use App\Core\Entities\StockMovementEntity;
use App\Core\Enums\ItemDocumentType;
use App\Core\Enums\StockIncidentStatus;
use App\Core\Enums\StockIncidentType;
use App\Core\Interfaces\IItemStockRepository;
use App\Core\ValueObjects\KardexFilters;
use App\Models\ItemStock;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;

/**
 * Balances and movements. Every query filters by company explicitly (D15, layer 2).
 */
class EloquentItemStockRepository implements IItemStockRepository
{
    public function lockForMovement(int $warehouseId, int $itemId, int $companyId): ItemStockEntity
    {
        // The first movement of an item in a warehouse creates its row; two at once race on the
        // unique key and the loser simply finds the row (DS7).
        $now = now();
        DB::table('item_stocks')->insertOrIgnore([
            'company_id' => $companyId,
            'warehouse_id' => $warehouseId,
            'item_id' => $itemId,
            'quantity' => '0',
            'average_unit_cost' => '0',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $model = ItemStock::withoutGlobalScopes()
            ->with('warehouse')
            ->where('company_id', $companyId)
            ->where('warehouse_id', $warehouseId)
            ->where('item_id', $itemId)
            ->lockForUpdate()
            ->firstOrFail();

        return StockMapper::stockToEntity($model);
    }

    public function applyMovement(ItemStockEntity $stock, StockMovementEntity $movement, int $companyId): StockMovementEntity
    {
        $model = StockMovement::withoutGlobalScopes()->create([
            'company_id' => $companyId,
            'warehouse_id' => $movement->warehouseId,
            'item_document_line_id' => $movement->itemDocumentLineId,
            'item_id' => $movement->itemId,
            'direction' => $movement->direction,
            'quantity' => $movement->quantity,
            'unit_cost' => $movement->unitCost,
            'total_cost' => $movement->totalCost,
            'balance_after' => $movement->balanceAfter,
            'average_cost_after' => $movement->averageCostAfter,
            'unvalued_quantity' => $movement->unvaluedQuantity,
            'unvalued_balance_after' => $movement->unvaluedBalanceAfter,
        ]);
        $saved = StockMapper::movementToEntity($model);
        $stock->apply($saved);

        ItemStock::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereKey($stock->getId())
            ->update([
                'quantity' => $stock->getQuantity(),
                'average_unit_cost' => $stock->getAverageUnitCost(),
                'unvalued_quantity' => $stock->getUnvaluedQuantity(),
                'last_movement_id' => $stock->getLastMovementId(),
                'updated_at' => now(),
            ]);

        return $saved;
    }

    public function findByItem(int $itemId, int $companyId): array
    {
        return ItemStock::withoutGlobalScopes()
            ->with('warehouse')
            ->where('company_id', $companyId)
            ->where('item_id', $itemId)
            ->orderBy('warehouse_id')
            ->get()
            ->map(fn (ItemStock $model) => StockMapper::stockToEntity($model))
            ->all();
    }

    public function saveCriticalStock(ItemStockEntity $stock): void
    {
        ItemStock::withoutGlobalScopes()
            ->where('company_id', $stock->getCompanyId())
            ->whereKey($stock->getId())
            ->update(['critical_stock' => $stock->getCriticalStock(), 'updated_at' => now()]);
    }

    public function summaries(array $itemIds, int $companyId): array
    {
        if ($itemIds === []) {
            return [];
        }

        $summaries = [];
        $rows = ItemStock::withoutGlobalScopes()
            ->with('warehouse')
            ->where('company_id', $companyId)
            ->whereIn('item_id', $itemIds)
            ->orderBy('warehouse_id')
            ->get();
        foreach ($rows as $row) {
            $stock = StockMapper::stockToEntity($row);
            $current = $summaries[$stock->getItemId()] ?? ['quantity' => '0.0000', 'unvalued_quantity' => '0.0000', 'value' => '0.0000', 'has_negative' => false, 'has_critical' => false, 'by_warehouse' => []];
            // Delivery D: what each active warehouse holds, for the available amount of a consumption or a transfer (H5).
            if ($stock->isWarehouseActive()) {
                $current['by_warehouse'][] = [
                    'warehouse_id' => $stock->getWarehouseId(),
                    'quantity' => $stock->getQuantity(),
                    'unvalued_quantity' => $stock->getUnvaluedQuantity(),
                    'value' => $stock->value(),
                ];
            }
            $summaries[$stock->getItemId()] = [
                'quantity' => bcadd($current['quantity'], $stock->getQuantity(), 4),
                // Delivery C: units waiting for their invoice are in the balance, not in the value.
                'unvalued_quantity' => bcadd($current['unvalued_quantity'], $stock->getUnvaluedQuantity(), 4),
                'value' => bcadd($current['value'], $stock->value(), 4),
                'has_negative' => $current['has_negative'],
                'has_critical' => $current['has_critical'],
                'by_warehouse' => $current['by_warehouse'],
            ];
        }

        $open = DB::table('stock_incidents')
            ->where('company_id', $companyId)
            ->whereIn('item_id', $itemIds)
            ->where('status', StockIncidentStatus::OPEN->value)
            ->whereIn('type', [StockIncidentType::NEGATIVE_BALANCE->value, StockIncidentType::CRITICAL_STOCK->value])
            ->get(['item_id', 'type']);
        foreach ($open as $incident) {
            $key = $incident->type === StockIncidentType::NEGATIVE_BALANCE->value ? 'has_negative' : 'has_critical';
            if (isset($summaries[(int) $incident->item_id])) {
                $summaries[(int) $incident->item_id][$key] = true;
            }
        }

        return $summaries;
    }

    /** "ALM-01 → ALM-02" for either half of a transfer and for the reversal of either half (E12). */
    private function transferRoute(object $row): ?string
    {
        $own = $row->warehouse_code;
        $type = $row->document_type === ItemDocumentType::REVERSAL->value ? $row->reversed_type : $row->document_type;
        $reversal = $row->document_type === ItemDocumentType::REVERSAL->value;

        return match ($type) {
            ItemDocumentType::TRANSFER_OUT->value => $own . ' → ' . ($reversal ? $row->reversed_in_code : $row->paired_in_code),
            ItemDocumentType::TRANSFER_IN->value => ($reversal ? $row->reversed_out_code : $row->paired_out_code) . ' → ' . $own,
            default => null,
        };
    }

    public function kardex(int $itemId, KardexFilters $filters, int $companyId): array
    {
        $query = DB::table('stock_movements as m')
            ->join('item_document_lines as l', 'l.id', '=', 'm.item_document_line_id')
            ->join('item_documents as d', 'd.id', '=', 'l.item_document_id')
            ->join('warehouses as w', 'w.id', '=', 'm.warehouse_id')
            ->leftJoin('batches as b', 'b.id', '=', 'd.batch_id')
            // Delivery D: the other half of a transfer, for "ALM-01 → ALM-02".
            ->leftJoin('item_documents as tout', 'tout.id', '=', 'd.paired_document_id')
            ->leftJoin('warehouses as wout', 'wout.id', '=', 'tout.warehouse_id')
            ->leftJoin('item_documents as tin', 'tin.paired_document_id', '=', 'd.id')
            ->leftJoin('warehouses as win', 'win.id', '=', 'tin.warehouse_id')
            // ...and of the transfer half a reversal undoes, so the reversal shows the same route.
            ->leftJoin('item_documents as rev', 'rev.id', '=', 'd.reverses_document_id')
            ->leftJoin('item_documents as rout', 'rout.id', '=', 'rev.paired_document_id')
            ->leftJoin('warehouses as wrout', 'wrout.id', '=', 'rout.warehouse_id')
            ->leftJoin('item_documents as rin', 'rin.paired_document_id', '=', 'rev.id')
            ->leftJoin('warehouses as wrin', 'wrin.id', '=', 'rin.warehouse_id')
            ->leftJoin('fiscal_documents as f', 'f.id', '=', 'd.fiscal_document_id')
            ->leftJoin('fiscal_document_types as ft', 'ft.id', '=', 'f.fiscal_document_type_id')
            ->leftJoin('providers as p', 'p.id', '=', 'f.provider_id')
            ->where('m.company_id', $companyId)
            ->where('m.item_id', $itemId)
            ->when($filters->warehouseId !== null, fn ($q) => $q->where('m.warehouse_id', $filters->warehouseId))
            ->when($filters->from !== null, fn ($q) => $q->where('d.occurred_at', '>=', $filters->from . ' 00:00:00'))
            ->when($filters->to !== null, fn ($q) => $q->where('d.occurred_at', '<=', $filters->to . ' 23:59:59'));

        $total = (clone $query)->count();
        $rows = $query
            ->orderByDesc('m.id')
            ->forPage($filters->page, $filters->perPage)
            ->get([
                'm.id', 'm.direction', 'm.quantity', 'm.unit_cost', 'm.total_cost', 'm.balance_after', 'm.average_cost_after',
                'm.unvalued_quantity', 'm.unvalued_balance_after',
                'w.name as warehouse_name', 'w.code as warehouse_code', 'wout.code as paired_out_code', 'win.code as paired_in_code',
                'rev.type as reversed_type', 'wrout.code as reversed_out_code', 'wrin.code as reversed_in_code', 'd.id as document_id', 'd.number as document_number', 'd.type as document_type',
                'd.occurred_at', 'b.name as batch_name', 'f.id as fiscal_document_id', 'f.point_of_sale', 'f.number as fiscal_number',
                'f.reference', 'ft.name as fiscal_type_name', 'p.name as provider_name',
            ]);

        return [
            'data' => $rows->map(fn (object $row) => [
                'id' => (int) $row->id,
                'occurred_at' => (string) $row->occurred_at,
                'warehouse' => $row->warehouse_name,
                'document_id' => (int) $row->document_id,
                'document_number' => (int) $row->document_number,
                'document_type' => $row->document_type,
                'document_type_label' => ItemDocumentType::from($row->document_type)->label(),
                'batch' => $row->batch_name,
                'transfer' => $this->transferRoute($row),
                'fiscal_document' => $row->fiscal_document_id === null ? null : [
                    'id' => (int) $row->fiscal_document_id,
                    'label' => $row->fiscal_type_name . ' ' . FiscalDocumentEntity::formatNumber(
                        $row->point_of_sale === null ? null : (int) $row->point_of_sale,
                        $row->fiscal_number === null ? null : (int) $row->fiscal_number,
                        $row->reference,
                    ),
                    'provider' => $row->provider_name,
                ],
                'direction' => (int) $row->direction,
                'quantity' => DecimalColumn::read($row->quantity, 4),
                'unit_cost' => DecimalColumn::readNullable($row->unit_cost, 6),
                'total_cost' => DecimalColumn::read($row->total_cost, 4),
                'balance_after' => DecimalColumn::read($row->balance_after, 4),
                'average_cost_after' => DecimalColumn::read($row->average_cost_after, 6),
                'unvalued_quantity' => DecimalColumn::read($row->unvalued_quantity, 4),
                'unvalued_balance_after' => DecimalColumn::read($row->unvalued_balance_after, 4),
            ])->all(),
            'total' => $total,
        ];
    }

    public function reconcile(): array
    {
        $differences = [];
        foreach (ItemStock::withoutGlobalScopes()->orderBy('id')->get() as $model) {
            $stock = StockMapper::stockToEntity($model);
            $sum = '0';
            $movements = StockMovement::withoutGlobalScopes()
                ->where('warehouse_id', $stock->getWarehouseId())
                ->where('item_id', $stock->getItemId())
                ->get(['direction', 'quantity']);
            foreach ($movements as $movement) {
                $sum = bcadd($sum, bcmul((string) $movement->direction, DecimalColumn::read($movement->quantity, 4), 4), 4);
            }

            $last = $stock->getLastMovementId() === null ? null
                : StockMapper::movementToEntity(StockMovement::withoutGlobalScopes()->findOrFail($stock->getLastMovementId()));
            $problems = [];
            if (bccomp($sum, $stock->getQuantity(), 4) !== 0) {
                $problems[] = "saldo {$stock->getQuantity()} y movimientos {$sum}";
            }
            if ($last !== null && bccomp($last->balanceAfter, $stock->getQuantity(), 4) !== 0) {
                $problems[] = "saldo {$stock->getQuantity()} y último movimiento {$last->balanceAfter}";
            }
            if ($last !== null && bccomp($last->averageCostAfter, $stock->getAverageUnitCost(), 6) !== 0) {
                $problems[] = "promedio {$stock->getAverageUnitCost()} y último movimiento {$last->averageCostAfter}";
            }
            if ($last !== null && bccomp($last->unvaluedBalanceAfter, $stock->getUnvaluedQuantity(), 4) !== 0) {
                $problems[] = "sin valorizar {$stock->getUnvaluedQuantity()} y último movimiento {$last->unvaluedBalanceAfter}";
            }
            if ($problems !== []) {
                $differences[] = [
                    'company_id' => $stock->getCompanyId(),
                    'warehouse_id' => $stock->getWarehouseId(),
                    'item_id' => $stock->getItemId(),
                    'problems' => $problems,
                ];
            }
        }

        return $differences;
    }
}
