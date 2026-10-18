<?php

declare(strict_types=1);

namespace App\Application\Mappers;

use App\Core\Entities\FiscalDocumentEntity;
use App\Core\Entities\FiscalDocumentLineEntity;
use App\Core\Entities\FiscalDocumentTaxEntity;
use App\Core\Enums\FiscalDocumentStatus;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentLine;
use App\Models\FiscalDocumentLineTax;
use App\Models\FiscalDocumentTax;

final class FiscalDocumentMapper
{
    public static function toEntity(FiscalDocument $model): FiscalDocumentEntity
    {
        $lines = $model->relationLoaded('lines')
            ? $model->lines->map(fn (FiscalDocumentLine $line) => self::lineToEntity($line))->values()->all()
            : [];
        $documentTaxes = $model->relationLoaded('documentTaxes')
            ? $model->documentTaxes->map(fn (FiscalDocumentTax $tax) => self::documentTaxToEntity($tax))->values()->all()
            : [];
        $operation = $model->relationLoaded('stockOperation') ? $model->stockOperation : null;
        $related = $model->relationLoaded('related') ? $model->related : null;

        return new FiscalDocumentEntity(
            (int) $model->id,
            (int) $model->company_id,
            (int) $model->provider_id,
            FiscalCatalogMapper::typeToEntity($model->type),
            $model->point_of_sale,
            $model->number,
            $model->reference,
            $model->issued_at->format('Y-m-d'),
            $model->related_fiscal_document_id,
            DecimalColumn::read($model->lines_amount, 4),
            DecimalColumn::read($model->line_taxes_amount, 4),
            DecimalColumn::read($model->document_taxes_amount, 4),
            DecimalColumn::read($model->total_amount, 4),
            DecimalColumn::readNullable($model->declared_total, 4),
            $lines,
            $documentTaxes,
            FiscalDocumentStatus::from($model->status),
            $model->void_reason,
            $model->voided_at?->toIso8601String(),
            $model->notes,
            $model->idempotency_key,
            $model->created_by,
            $model->relationLoaded('provider') ? $model->provider?->name : null,
            $model->relationLoaded('creator') ? $model->creator?->name : null,
            $model->created_at?->toIso8601String(),
            $related === null ? null : $related->type->name . ' ' . FiscalDocumentEntity::formatNumber($related->point_of_sale, $related->number, $related->reference),
            $operation?->id,
            $operation?->number,
        );
    }

    public static function lineToEntity(FiscalDocumentLine $model): FiscalDocumentLineEntity
    {
        $item = $model->relationLoaded('item') ? $model->item : null;
        $unit = $item !== null && $item->relationLoaded('unit') ? $item->unit : null;
        $taxes = $model->relationLoaded('taxes')
            ? $model->taxes->map(fn (FiscalDocumentLineTax $tax) => self::lineTaxToEntity($tax))->values()->all()
            : [];

        return new FiscalDocumentLineEntity(
            (int) $model->position,
            (int) $model->item_id,
            DecimalColumn::read($model->input_quantity, 4),
            $model->input_unit_id,
            $model->input_presentation_id,
            DecimalColumn::read($model->quantity, 4),
            DecimalColumn::read($model->unit_price, 6),
            DecimalColumn::read($model->line_amount, 4),
            DecimalColumn::read($model->non_recoverable_tax_amount, 4),
            DecimalColumn::read($model->cost_amount, 4),
            DecimalColumn::read($model->unit_cost, 6),
            $model->batch_id,
            $model->heads_treated,
            $taxes,
            (bool) ($item?->track_stock ?? false),
            (int) $model->id,
            $item?->name,
            $unit?->symbol,
            self::inputLabel($model),
            $model->relationLoaded('batch') ? $model->batch?->name : null,
        );
    }

    /** What the paper says the quantity was in: the presentation's name or the unit's symbol. */
    public static function inputLabel(FiscalDocumentLine|\App\Models\ItemDocumentLine $model): ?string
    {
        if ($model->input_presentation_id !== null) {
            return $model->relationLoaded('inputPresentation') ? $model->inputPresentation?->name : null;
        }

        return $model->relationLoaded('inputUnit') ? $model->inputUnit?->symbol : null;
    }

    private static function lineTaxToEntity(FiscalDocumentLineTax $model): FiscalDocumentTaxEntity
    {
        return new FiscalDocumentTaxEntity(
            (int) $model->tax_id,
            $model->tax->code,
            $model->tax->name,
            $model->tax_rate_id,
            $model->tax_rate_id !== null ? $model->taxRate?->name : null,
            DecimalColumn::readNullable($model->rate, 4),
            DecimalColumn::readNullable($model->taxable_base, 4),
            DecimalColumn::read($model->amount, 4),
            (bool) $model->is_recoverable,
            (int) $model->id,
        );
    }

    private static function documentTaxToEntity(FiscalDocumentTax $model): FiscalDocumentTaxEntity
    {
        return new FiscalDocumentTaxEntity(
            (int) $model->tax_id,
            $model->tax->code,
            $model->tax->name,
            null,
            null,
            DecimalColumn::readNullable($model->rate, 4),
            DecimalColumn::readNullable($model->taxable_base, 4),
            DecimalColumn::read($model->amount, 4),
            (bool) $model->is_recoverable,
            (int) $model->id,
        );
    }
}
