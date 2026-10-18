<?php

declare(strict_types=1);

namespace App\Application\Mappers;

use App\Core\Entities\FiscalDocumentEntity;
use App\Core\Entities\ItemDocumentEntity;
use App\Core\Entities\ItemDocumentLineEntity;
use App\Core\Entities\ItemStockEntity;
use App\Core\Entities\StockIncidentEntity;
use App\Core\Entities\StockMovementEntity;
use App\Core\Entities\WarehouseEntity;
use App\Core\Enums\ItemDocumentType;
use App\Core\Enums\StockIncidentStatus;
use App\Core\Enums\StockIncidentType;
use App\Models\ItemDocument;
use App\Models\ItemDocumentLine;
use App\Models\ItemStock;
use App\Models\StockIncident;
use App\Models\StockMovement;
use App\Models\Warehouse;

final class StockMapper
{
    public static function warehouseToEntity(Warehouse $model): WarehouseEntity
    {
        return new WarehouseEntity(
            (int) $model->id,
            (int) $model->company_id,
            $model->code,
            $model->name,
            (bool) $model->is_default,
            (bool) $model->is_active,
        );
    }

    public static function documentToEntity(ItemDocument $model): ItemDocumentEntity
    {
        $fiscal = $model->relationLoaded('fiscalDocument') ? $model->fiscalDocument : null;
        $reversedBy = $model->relationLoaded('reversedBy') ? $model->reversedBy : null;

        return new ItemDocumentEntity(
            (int) $model->id,
            (int) $model->company_id,
            (int) $model->number,
            ItemDocumentType::from($model->type),
            (int) $model->warehouse_id,
            $model->occurred_at->toIso8601String(),
            $model->lines->map(fn (ItemDocumentLine $line) => self::lineToEntity($line))->values()->all(),
            $model->fiscal_document_id,
            $model->batch_id,
            $model->heads_treated,
            $model->reverses_document_id,
            $model->reason,
            $model->notes,
            $model->idempotency_key,
            $model->created_by,
            $model->relationLoaded('warehouse') ? $model->warehouse?->name : null,
            $fiscal === null ? null
                : $fiscal->type->name . ' ' . FiscalDocumentEntity::formatNumber($fiscal->point_of_sale, $fiscal->number, $fiscal->reference),
            $model->relationLoaded('batch') ? $model->batch?->name : null,
            $model->relationLoaded('reverses') ? $model->reverses?->number : null,
            $reversedBy?->id,
            $reversedBy?->number,
            $model->relationLoaded('creator') ? $model->creator?->name : null,
            $model->created_at?->toIso8601String(),
        );
    }

    public static function lineToEntity(ItemDocumentLine $model): ItemDocumentLineEntity
    {
        $item = $model->relationLoaded('item') ? $model->item : null;
        $movement = $model->relationLoaded('movement') ? $model->movement : null;

        return new ItemDocumentLineEntity(
            (int) $model->position,
            (int) $model->item_id,
            DecimalColumn::read($model->input_quantity, 4),
            $model->input_unit_id,
            $model->input_presentation_id,
            DecimalColumn::read($model->quantity, 4),
            DecimalColumn::readNullable($model->input_unit_price, 6),
            DecimalColumn::readNullable($model->unit_cost, 6),
            DecimalColumn::read($model->total_cost, 4),
            $model->fiscal_document_line_id,
            DecimalColumn::readNullable($model->counted_quantity, 4),
            $model->reverses_line_id,
            $movement === null ? null : self::movementToEntity($movement),
            (int) $model->id,
            $item?->name,
            $item !== null && $item->relationLoaded('unit') ? $item->unit?->symbol : null,
            FiscalDocumentMapper::inputLabel($model),
            DecimalColumn::read($model->unvalued_quantity ?? '0', 4),
            DecimalColumn::readNullable($model->unabsorbed_cost, 4),
        );
    }

    public static function movementToEntity(StockMovement $model): StockMovementEntity
    {
        return new StockMovementEntity(
            (int) $model->warehouse_id,
            (int) $model->item_id,
            (int) $model->direction,
            DecimalColumn::read($model->quantity, 4),
            DecimalColumn::readNullable($model->unit_cost, 6),
            DecimalColumn::read($model->total_cost, 4),
            DecimalColumn::read($model->balance_after, 4),
            DecimalColumn::read($model->average_cost_after, 6),
            (int) $model->id,
            (int) $model->item_document_line_id,
            DecimalColumn::read($model->unvalued_quantity ?? '0', 4),
            DecimalColumn::read($model->unvalued_balance_after ?? '0', 4),
        );
    }

    public static function stockToEntity(ItemStock $model): ItemStockEntity
    {
        return new ItemStockEntity(
            (int) $model->id,
            (int) $model->company_id,
            (int) $model->warehouse_id,
            (int) $model->item_id,
            DecimalColumn::read($model->quantity, 4),
            DecimalColumn::read($model->average_unit_cost, 6),
            DecimalColumn::readNullable($model->critical_stock, 4),
            $model->last_movement_id,
            $model->relationLoaded('warehouse') ? $model->warehouse?->name : null,
            DecimalColumn::read($model->unvalued_quantity ?? '0', 4),
        );
    }

    public static function incidentToEntity(StockIncident $model): StockIncidentEntity
    {
        return new StockIncidentEntity(
            (int) $model->id,
            (int) $model->company_id,
            (int) $model->warehouse_id,
            (int) $model->item_id,
            StockIncidentType::from($model->type),
            $model->stock_movement_id,
            $model->detail,
            $model->metadata ?? [],
            StockIncidentStatus::from($model->status),
            $model->resolution,
            $model->resolved_by_user_id,
            $model->resolved_at?->toIso8601String(),
            $model->raised_by_user_id,
            $model->created_at?->toIso8601String(),
            $model->relationLoaded('item') ? $model->item?->name : null,
            $model->relationLoaded('warehouse') ? $model->warehouse?->name : null,
            $model->relationLoaded('resolver') ? $model->resolver?->name : null,
        );
    }
}
