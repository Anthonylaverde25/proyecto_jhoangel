<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Mappers\DecimalColumn;
use App\Core\Enums\ItemDocumentType;
use App\Core\Enums\SemenPreservation;
use App\Core\Enums\SemenType;
use App\Core\Enums\StrawColor;
use App\Core\Enums\StrawSize;
use App\Core\Interfaces\ISemenInventoryReader;
use Illuminate\Support\Facades\DB;

/** Every query filters by company explicitly (D15, layer 2). */
class EloquentSemenInventoryReader implements ISemenInventoryReader
{
    public function inventory(int $companyId): array
    {
        $rows = DB::table('item_semen_details as d')
            ->join('items as i', 'i.id', '=', 'd.item_id')
            ->join('semen_sires as s', 's.id', '=', 'd.semen_sire_id')
            ->leftJoin('breeds as b', 'b.id', '=', 's.breed_id')
            ->leftJoin('caravans as c', 'c.id', '=', 's.caravan_id')
            ->where('d.company_id', $companyId)
            ->orderBy('s.name')->orderBy('d.semen_type')
            ->get([
                'i.id', 'i.code', 'i.name', 'i.is_active', 'd.semen_type', 'd.preservation', 'd.straw_size', 'd.straw_color', 'd.cap_color',
                's.id as sire_id', 's.name as sire_name', 's.registry_code', 's.stud_name', 'b.name as breed_name', 'c.identification as caravan_tag',
            ]);
        if ($rows->isEmpty()) {
            return [];
        }
        $itemIds = $rows->pluck('id')->map(fn ($id) => (int) $id)->all();
        $warehouses = DB::table('warehouses')->where('company_id', $companyId)->get(['id', 'code', 'name', 'kind', 'is_active'])->keyBy('id');

        $stocks = DB::table('item_stocks')->where('company_id', $companyId)->whereIn('item_id', $itemIds)
            ->get(['item_id', 'warehouse_id', 'quantity', 'average_unit_cost'])->groupBy('item_id');
        $lots = DB::table('semen_lots')->where('company_id', $companyId)->whereIn('item_id', $itemIds)->orderBy('code')
            ->get(['id', 'item_id', 'code', 'collection_date'])->groupBy('item_id');
        $lotStocks = DB::table('semen_lot_stocks')->where('company_id', $companyId)
            ->whereIn('semen_lot_id', $lots->flatten()->pluck('id')->all())
            ->get(['semen_lot_id', 'warehouse_id', 'quantity'])->groupBy('semen_lot_id');
        $firstEntries = $this->firstEntries($companyId, $lots->flatten()->pluck('id')->all());
        $pending = DB::table('semen_unidentified_entries')->where('company_id', $companyId)->where('status', 'OPEN')
            ->whereIn('item_id', $itemIds)
            ->groupBy('item_id', 'warehouse_id', 'direction')
            ->selectRaw('item_id, warehouse_id, direction, SUM(quantity - identified_quantity) AS pending')
            ->get()->groupBy('item_id');

        $warehouse = fn (int $id) => ['id' => $id, 'code' => $warehouses[$id]->code ?? null, 'name' => $warehouses[$id]->name ?? null, 'kind' => $warehouses[$id]->kind ?? null];

        return $rows->map(function ($row) use ($stocks, $lots, $lotStocks, $firstEntries, $pending, $warehouse) {
            $itemId = (int) $row->id;
            $byWarehouse = collect($stocks[$itemId] ?? [])->filter(fn ($s) => bccomp(DecimalColumn::read($s->quantity, 4), '0', 4) !== 0)->map(function ($s) use ($warehouse) {
                $quantity = DecimalColumn::read($s->quantity, 4);
                $average = DecimalColumn::read($s->average_unit_cost, 6);

                return ['warehouse' => $warehouse((int) $s->warehouse_id), 'quantity' => $quantity, 'average_unit_cost' => $average, 'value' => bcmul($quantity, $average, 4)];
            })->values()->all();
            $unidentified = [];
            foreach ($pending[$itemId] ?? [] as $p) {
                $key = (int) $p->warehouse_id;
                $unidentified[$key] ??= ['warehouse' => $warehouse($key), 'pending_in' => '0.0000', 'pending_out' => '0.0000'];
                $unidentified[$key][(int) $p->direction > 0 ? 'pending_in' : 'pending_out'] = DecimalColumn::read($p->pending, 4);
            }

            return [
                'item' => ['id' => $itemId, 'code' => $row->code, 'name' => $row->name, 'is_active' => (bool) $row->is_active],
                'sire' => [
                    'id' => (int) $row->sire_id, 'name' => $row->sire_name, 'registry_code' => $row->registry_code,
                    'breed' => $row->breed_name, 'stud_name' => $row->stud_name, 'caravan_tag' => $row->caravan_tag,
                ],
                'semen_type' => ['value' => $row->semen_type, 'label' => SemenType::from($row->semen_type)->label()],
                'preservation' => ['value' => $row->preservation, 'label' => SemenPreservation::from($row->preservation)->label()],
                'straw_size' => $row->straw_size === null ? null : ['value' => $row->straw_size, 'label' => StrawSize::from($row->straw_size)->label()],
                'straw_color' => $row->straw_color === null ? null : ['value' => $row->straw_color, 'label' => StrawColor::from($row->straw_color)->label()],
                'cap_color' => $row->cap_color === null ? null : ['value' => $row->cap_color, 'label' => StrawColor::from($row->cap_color)->label()],
                'stocks' => $byWarehouse,
                'lots' => collect($lots[$itemId] ?? [])->map(fn ($lot) => [
                    'id' => (int) $lot->id,
                    'code' => $lot->code,
                    'collection_date' => $lot->collection_date,
                    'first_entry' => $firstEntries[(int) $lot->id] ?? null,
                    'balances' => collect($lotStocks[(int) $lot->id] ?? [])
                        ->filter(fn ($s) => bccomp(DecimalColumn::read($s->quantity, 4), '0', 4) > 0)
                        ->map(fn ($s) => ['warehouse' => $warehouse((int) $s->warehouse_id), 'quantity' => DecimalColumn::read($s->quantity, 4)])
                        ->values()->all(),
                ])->values()->all(),
                'unidentified' => array_values($unidentified),
            ];
        })->values()->all();
    }

    public function lotsInWarehouse(int $itemId, int $warehouseId, int $companyId): array
    {
        return DB::table('semen_lots as l')
            ->leftJoin('semen_lot_stocks as s', fn ($j) => $j->on('s.semen_lot_id', '=', 'l.id')->where('s.warehouse_id', '=', $warehouseId))
            ->where('l.company_id', $companyId)->where('l.item_id', $itemId)
            ->orderBy('l.code')
            ->get(['l.id', 'l.code', 'l.collection_date', 's.quantity'])
            ->map(fn ($r) => ['id' => (int) $r->id, 'code' => $r->code, 'collection_date' => $r->collection_date, 'quantity' => DecimalColumn::read($r->quantity ?? '0', 4)])
            ->all();
    }

    public function lotDetail(int $lotId, int $companyId): ?array
    {
        $lot = DB::table('semen_lots as l')->join('items as i', 'i.id', '=', 'l.item_id')
            ->where('l.company_id', $companyId)->where('l.id', $lotId)
            ->first(['l.id', 'l.code', 'l.collection_date', 'l.collection_code', 'l.notes', 'i.id as item_id', 'i.code as item_code', 'i.name as item_name']);
        if ($lot === null) {
            return null;
        }

        $balances = DB::table('semen_lot_stocks as s')->join('warehouses as w', 'w.id', '=', 's.warehouse_id')
            ->where('s.company_id', $companyId)->where('s.semen_lot_id', $lotId)->orderBy('w.code')
            ->get(['w.id', 'w.code', 'w.name', 's.quantity'])
            ->map(fn ($r) => ['warehouse' => ['id' => (int) $r->id, 'code' => $r->code, 'name' => $r->name], 'quantity' => DecimalColumn::read($r->quantity, 4)])
            ->all();

        $movements = DB::table('item_document_lines as l')
            ->join('item_documents as d', 'd.id', '=', 'l.item_document_id')
            ->join('stock_movements as m', 'm.item_document_line_id', '=', 'l.id')
            ->join('warehouses as w', 'w.id', '=', 'd.warehouse_id')
            ->leftJoin('providers as p', 'p.id', '=', 'd.provider_id')
            ->where('l.company_id', $companyId)->where('l.semen_lot_id', $lotId)
            ->orderBy('d.occurred_at')->orderBy('d.id')
            ->get(['d.id', 'd.number', 'd.type', 'd.occurred_at', 'w.code as warehouse_code', 'p.name as provider_name', 'm.direction', 'm.quantity', 'l.unit_cost'])
            ->map(fn ($r) => [
                'document' => ['id' => (int) $r->id, 'number' => (int) $r->number, 'type' => $r->type, 'type_label' => ItemDocumentType::from($r->type)->label(), 'occurred_at' => $r->occurred_at],
                'warehouse_code' => $r->warehouse_code,
                'provider' => $r->provider_name,
                'direction' => (int) $r->direction,
                'quantity' => DecimalColumn::read($r->quantity, 4),
                'unit_cost' => DecimalColumn::readNullable($r->unit_cost, 6),
            ])->all();

        $identifications = DB::table('semen_lot_identifications as i')
            ->join('semen_unidentified_entries as e', 'e.id', '=', 'i.semen_unidentified_entry_id')
            ->join('item_document_lines as l', 'l.id', '=', 'e.item_document_line_id')
            ->join('item_documents as d', 'd.id', '=', 'l.item_document_id')
            ->join('warehouses as w', 'w.id', '=', 'e.warehouse_id')
            ->leftJoin('users as u', 'u.id', '=', 'i.identified_by')
            ->where('i.company_id', $companyId)->where('i.semen_lot_id', $lotId)->whereNull('i.voided_at')
            ->orderBy('i.id')
            ->get(['i.quantity', 'i.created_at', 'e.direction', 'd.id as document_id', 'd.number', 'd.type', 'w.code as warehouse_code', 'u.name as user_name'])
            ->map(fn ($r) => [
                'quantity' => DecimalColumn::read($r->quantity, 4),
                'direction' => (int) $r->direction,
                'identified_at' => $r->created_at,
                'identified_by' => $r->user_name,
                'warehouse_code' => $r->warehouse_code,
                'document' => ['id' => (int) $r->document_id, 'number' => (int) $r->number, 'type' => $r->type, 'type_label' => ItemDocumentType::from($r->type)->label()],
            ])->all();

        return [
            'id' => (int) $lot->id,
            'code' => $lot->code,
            'collection_date' => $lot->collection_date,
            'collection_code' => $lot->collection_code,
            'notes' => $lot->notes,
            'item' => ['id' => (int) $lot->item_id, 'code' => $lot->item_code, 'name' => $lot->item_name],
            'balances' => $balances,
            'movements' => $movements,
            'identifications' => $identifications,
        ];
    }

    /**
     * The first entry of each lot: by the semen entry, or by an identification.
     *
     * @param list<int> $lotIds
     * @return array<int, array{occurred_at: string, provider: ?string}>
     */
    private function firstEntries(int $companyId, array $lotIds): array
    {
        if ($lotIds === []) {
            return [];
        }
        $first = [];
        $rows = DB::table('item_document_lines as l')
            ->join('item_documents as d', 'd.id', '=', 'l.item_document_id')
            ->leftJoin('providers as p', 'p.id', '=', 'd.provider_id')
            ->where('l.company_id', $companyId)->whereIn('l.semen_lot_id', $lotIds)->where('d.type', ItemDocumentType::SEMEN_RECEIPT->value)
            ->orderBy('d.occurred_at')
            ->get(['l.semen_lot_id', 'd.occurred_at', 'p.name']);
        foreach ($rows as $r) {
            $first[(int) $r->semen_lot_id] ??= ['occurred_at' => (string) $r->occurred_at, 'provider' => $r->name];
        }
        $identified = DB::table('semen_lot_identifications as i')
            ->join('semen_unidentified_entries as e', 'e.id', '=', 'i.semen_unidentified_entry_id')
            ->join('item_document_lines as l', 'l.id', '=', 'e.item_document_line_id')
            ->join('item_documents as d', 'd.id', '=', 'l.item_document_id')
            ->leftJoin('providers as p', 'p.id', '=', 'd.provider_id')
            ->where('i.company_id', $companyId)->whereIn('i.semen_lot_id', $lotIds)->whereNull('i.voided_at')->where('e.direction', 1)
            ->orderBy('d.occurred_at')
            ->get(['i.semen_lot_id', 'd.occurred_at', 'p.name']);
        foreach ($identified as $r) {
            $first[(int) $r->semen_lot_id] ??= ['occurred_at' => (string) $r->occurred_at, 'provider' => $r->name];
        }

        return $first;
    }
}
