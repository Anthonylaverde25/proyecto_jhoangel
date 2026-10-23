<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Mappers\DecimalColumn;
use App\Application\Mappers\SemenMapper;
use App\Core\Entities\SemenUnidentifiedEntryEntity;
use App\Core\Interfaces\ISemenUnidentifiedRepository;
use App\Models\SemenLotIdentification;
use App\Models\SemenUnidentifiedEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Every query filters by company explicitly (D15, layer 2). */
class EloquentSemenUnidentifiedRepository implements ISemenUnidentifiedRepository
{
    public function create(SemenUnidentifiedEntryEntity $entry): SemenUnidentifiedEntryEntity
    {
        $model = SemenUnidentifiedEntry::withoutGlobalScopes()->create([
            'company_id' => $entry->getCompanyId(),
            'item_id' => $entry->getItemId(),
            'warehouse_id' => $entry->getWarehouseId(),
            'item_document_line_id' => $entry->getLineId(),
            'stock_incident_id' => $entry->getIncidentId(),
            'direction' => $entry->getDirection(),
            'quantity' => $entry->getQuantity(),
            'identified_quantity' => $entry->getIdentifiedQuantity(),
            'status' => $entry->getStatus(),
        ]);

        return SemenMapper::unidentifiedToEntity($model);
    }

    public function save(SemenUnidentifiedEntryEntity $entry): void
    {
        $this->query($entry->getCompanyId())->whereKey($entry->getId())->update([
            'stock_incident_id' => $entry->getIncidentId(),
            'identified_quantity' => $entry->getIdentifiedQuantity(),
            'status' => $entry->getStatus(),
        ]);
    }

    public function findByLine(int $lineId, int $companyId): ?SemenUnidentifiedEntryEntity
    {
        $model = $this->query($companyId)->where('item_document_line_id', $lineId)->first();

        return $model ? SemenMapper::unidentifiedToEntity($model) : null;
    }

    public function lockById(int $id, int $companyId): ?SemenUnidentifiedEntryEntity
    {
        $model = $this->query($companyId)->whereKey($id)->lockForUpdate()->first();

        return $model ? SemenMapper::unidentifiedToEntity($model) : null;
    }

    public function identifications(int $entryId, int $companyId): array
    {
        return SemenLotIdentification::withoutGlobalScopes()
            ->where('company_id', $companyId)->where('semen_unidentified_entry_id', $entryId)->whereNull('voided_at')
            ->orderBy('id')
            ->get()
            ->map(fn (SemenLotIdentification $i) => ['id' => (int) $i->id, 'lot_id' => (int) $i->semen_lot_id, 'quantity' => DecimalColumn::read($i->quantity, 4)])
            ->all();
    }

    public function addIdentification(int $entryId, int $lotId, string $quantity, ?int $userId, int $companyId): void
    {
        SemenLotIdentification::withoutGlobalScopes()->create([
            'company_id' => $companyId,
            'semen_unidentified_entry_id' => $entryId,
            'semen_lot_id' => $lotId,
            'quantity' => $quantity,
            'identified_by' => $userId,
        ]);
    }

    public function voidIdentifications(int $entryId, int $companyId): void
    {
        SemenLotIdentification::withoutGlobalScopes()
            ->where('company_id', $companyId)->where('semen_unidentified_entry_id', $entryId)->whereNull('voided_at')
            ->update(['voided_at' => now()]);
    }

    public function pendingSplit(int $itemId, int $warehouseId, int $companyId): array
    {
        $rows = $this->query($companyId)
            ->where('item_id', $itemId)->where('warehouse_id', $warehouseId)
            ->where('status', SemenUnidentifiedEntryEntity::OPEN)
            ->groupBy('direction')
            ->selectRaw('direction, SUM(quantity - identified_quantity) AS pending')
            ->pluck('pending', 'direction');

        return [DecimalColumn::read($rows[1] ?? '0', 4), DecimalColumn::read($rows[-1] ?? '0', 4)];
    }

    public function pendingByWarehouse(int $itemId, int $companyId): array
    {
        return $this->query($companyId)
            ->where('item_id', $itemId)
            ->where('status', SemenUnidentifiedEntryEntity::OPEN)
            ->groupBy('warehouse_id')
            ->selectRaw('warehouse_id, SUM(direction * (quantity - identified_quantity)) AS pending')
            ->pluck('pending', 'warehouse_id')
            ->map(fn ($pending) => DecimalColumn::read($pending, 4))
            ->all();
    }

    public function listOpen(int $companyId, ?int $itemId, ?int $warehouseId): array
    {
        return DB::table('semen_unidentified_entries as e')
            ->join('item_document_lines as l', 'l.id', '=', 'e.item_document_line_id')
            ->join('item_documents as d', 'd.id', '=', 'l.item_document_id')
            ->join('items as i', 'i.id', '=', 'e.item_id')
            ->join('warehouses as w', 'w.id', '=', 'e.warehouse_id')
            ->where('e.company_id', $companyId)
            ->where('e.status', SemenUnidentifiedEntryEntity::OPEN)
            ->when($itemId !== null, fn ($q) => $q->where('e.item_id', $itemId))
            ->when($warehouseId !== null, fn ($q) => $q->where('e.warehouse_id', $warehouseId))
            ->orderByDesc('e.id')
            ->get([
                'e.id', 'e.item_id', 'i.name as item_name', 'e.warehouse_id', 'w.code as warehouse_code', 'w.name as warehouse_name',
                'e.direction', 'e.quantity', 'e.identified_quantity', 'e.stock_incident_id',
                'd.id as document_id', 'd.number as document_number', 'd.type as document_type', 'd.occurred_at',
            ])
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'item' => ['id' => (int) $row->item_id, 'name' => $row->item_name],
                'warehouse' => ['id' => (int) $row->warehouse_id, 'code' => $row->warehouse_code, 'name' => $row->warehouse_name],
                'direction' => (int) $row->direction,
                'quantity' => DecimalColumn::read($row->quantity, 4),
                'pending_quantity' => bcsub(DecimalColumn::read($row->quantity, 4), DecimalColumn::read($row->identified_quantity, 4), 4),
                'stock_incident_id' => $row->stock_incident_id === null ? null : (int) $row->stock_incident_id,
                'document' => ['id' => (int) $row->document_id, 'number' => (int) $row->document_number, 'type' => $row->document_type, 'occurred_at' => $row->occurred_at],
            ])
            ->all();
    }

    private function query(int $companyId): Builder
    {
        return SemenUnidentifiedEntry::withoutGlobalScopes()->where('company_id', $companyId);
    }
}
