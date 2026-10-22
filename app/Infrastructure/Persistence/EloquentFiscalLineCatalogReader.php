<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Core\Interfaces\IFiscalLineCatalogReader;
use App\Core\ValueObjects\FiscalCatalogItem;
use Illuminate\Support\Facades\DB;

class EloquentFiscalLineCatalogReader implements IFiscalLineCatalogReader
{
    public function activeItems(int $companyId): array
    {
        $items = DB::table('items')
            ->leftJoin('units_of_measure', 'units_of_measure.id', '=', 'items.unit_of_measure_id')
            ->leftJoin('item_categories', 'item_categories.id', '=', 'items.item_category_id')
            ->where('items.company_id', $companyId)
            ->where('items.is_active', true)
            ->orderBy('items.id')
            ->get(['items.id', 'items.name', 'items.type', 'items.description', 'units_of_measure.symbol as unit', 'item_categories.name as category']);

        $presentations = DB::table('item_presentations')
            ->whereIn('item_id', $items->pluck('id'))
            ->where('is_active', true)
            ->orderBy('id')
            ->get(['item_id', 'name'])
            ->groupBy('item_id');

        return $items->map(fn (object $row) => new FiscalCatalogItem(
            (int) $row->id,
            (string) $row->name,
            (string) $row->type,
            $row->unit !== null ? (string) $row->unit : null,
            $row->category !== null ? (string) $row->category : null,
            $row->description !== null ? mb_substr((string) $row->description, 0, 160) : null,
            ($presentations[$row->id] ?? collect())->pluck('name')->map(fn ($n) => (string) $n)->values()->all(),
        ))->values()->all();
    }

    public function itemIdsOfProvider(int $providerId, int $companyId): array
    {
        return DB::table('item_provider')
            ->where('company_id', $companyId)
            ->where('provider_id', $providerId)
            ->pluck('item_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    public function vatRateIdsByCode(): array
    {
        return DB::table('tax_rates')
            ->join('taxes', 'taxes.id', '=', 'tax_rates.tax_id')
            ->where('taxes.kind', 'VAT')
            ->whereNull('tax_rates.valid_to')
            ->pluck('tax_rates.id', 'tax_rates.code')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
