<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Core\Entities\ServiceOrderInseminationEntity;
use App\Core\Enums\ServiceMethod;
use App\Core\Enums\ServiceOrderStatus;
use App\Core\Interfaces\IServiceOrderInseminationRepository;
use App\Core\ValueObjects\InseminationFilters;
use App\Models\ServiceOrderFemale;
use App\Models\ServiceOrderInsemination;
use Illuminate\Support\Facades\DB;

class EloquentServiceOrderInseminationRepository implements IServiceOrderInseminationRepository
{
    public function create(ServiceOrderInseminationEntity $insemination): ServiceOrderInseminationEntity
    {
        $model = ServiceOrderInsemination::withoutGlobalScopes()->create([
            'company_id' => $insemination->getCompanyId(),
            'service_order_id' => $insemination->getServiceOrderId(),
            'female_caravan_id' => $insemination->getFemaleCaravanId(),
            'inseminated_on' => $insemination->getInseminatedOn(),
            'item_id' => $insemination->getItemId(),
            'semen_lot_id' => $insemination->getSemenLotId(),
            'straws_used' => $insemination->getStrawsUsed(),
            'warehouse_id' => $insemination->getWarehouseId(),
            'item_document_line_id' => $insemination->getItemDocumentLineId(),
            'notes' => $insemination->getNotes(),
            'created_by' => $insemination->getCreatedBy(),
        ]);

        return self::toEntity($model);
    }

    public function save(ServiceOrderInseminationEntity $insemination): void
    {
        ServiceOrderInsemination::withoutGlobalScopes()
            ->where('id', $insemination->getId())
            ->where('company_id', $insemination->getCompanyId())
            ->update([
                'item_document_line_id' => $insemination->getItemDocumentLineId(),
                'voided_at' => $insemination->getVoidedAt(),
                'void_reason' => $insemination->getVoidReason(),
                'voided_by' => $insemination->getVoidedBy(),
            ]);
    }

    public function lockById(int $id, int $companyId): ?ServiceOrderInseminationEntity
    {
        $model = ServiceOrderInsemination::withoutGlobalScopes()
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->lockForUpdate()
            ->first();

        return $model === null ? null : self::toEntity($model);
    }

    public function liveByLines(array $lineIds, int $companyId): array
    {
        if ($lineIds === []) {
            return [];
        }

        return ServiceOrderInsemination::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('item_document_line_id', $lineIds)
            ->whereNull('voided_at')
            ->orderBy('id')
            ->get()
            ->map(fn (ServiceOrderInsemination $model) => self::toEntity($model))
            ->all();
    }

    public function liveCountByOrder(int $orderId): int
    {
        return ServiceOrderInsemination::withoutGlobalScopes()
            ->where('service_order_id', $orderId)
            ->whereNull('voided_at')
            ->count();
    }

    public function liveDuplicates(int $orderId, array $pairs): array
    {
        if ($pairs === []) {
            return [];
        }

        return ServiceOrderInsemination::withoutGlobalScopes()
            ->where('service_order_id', $orderId)
            ->whereNull('voided_at')
            ->whereIn('female_caravan_id', array_unique(array_map(fn (array $pair) => $pair[0], $pairs)))
            ->get(['female_caravan_id', 'inseminated_on'])
            ->map(fn (ServiceOrderInsemination $row) => $row->female_caravan_id . '|' . $row->inseminated_on->format('Y-m-d'))
            ->all();
    }

    public function clearNotInseminatedReason(int $orderId, array $femaleIds): void
    {
        if ($femaleIds === []) {
            return;
        }

        ServiceOrderFemale::withoutGlobalScopes()
            ->where('service_order_id', $orderId)
            ->whereIn('female_caravan_id', $femaleIds)
            ->update(['not_inseminated_reason' => null]);
    }

    public function setNotInseminatedReasons(int $orderId, array $reasons): void
    {
        foreach ($reasons as $femaleId => $reason) {
            ServiceOrderFemale::withoutGlobalScopes()
                ->where('service_order_id', $orderId)
                ->where('female_caravan_id', (int) $femaleId)
                ->update(['not_inseminated_reason' => $reason]);
        }
    }

    public function listForOrder(int $orderId, int $companyId): array
    {
        return DB::table('service_order_inseminations as i')
            ->join('caravans as c', 'c.id', '=', 'i.female_caravan_id')
            ->join('items as it', 'it.id', '=', 'i.item_id')
            ->leftJoin('item_semen_details as d', 'd.item_id', '=', 'i.item_id')
            ->leftJoin('semen_sires as s', 's.id', '=', 'd.semen_sire_id')
            ->leftJoin('semen_lots as l', 'l.id', '=', 'i.semen_lot_id')
            ->join('warehouses as w', 'w.id', '=', 'i.warehouse_id')
            ->leftJoin('item_document_lines as dl', 'dl.id', '=', 'i.item_document_line_id')
            ->leftJoin('item_documents as doc', 'doc.id', '=', 'dl.item_document_id')
            ->where('i.service_order_id', $orderId)
            ->where('i.company_id', $companyId)
            ->orderBy('c.identification')
            ->orderBy('i.inseminated_on')
            ->orderBy('i.id')
            ->get([
                'i.id', 'i.female_caravan_id', 'c.identification', 'i.inseminated_on', 'i.item_id', 'it.name as item_name',
                'd.semen_type', 's.name as sire_name', 'i.semen_lot_id', 'l.code as lot_code', 'i.straws_used',
                'i.warehouse_id', 'w.code as warehouse_code', 'w.name as warehouse_name', 'doc.id as document_id',
                'doc.number as document_number', 'i.notes', 'i.voided_at', 'i.void_reason',
            ])
            ->map(fn (object $row) => [
                'id' => (int) $row->id,
                'female_caravan_id' => (int) $row->female_caravan_id,
                'identification' => (string) $row->identification,
                'inseminated_on' => substr((string) $row->inseminated_on, 0, 10),
                'item_id' => (int) $row->item_id,
                'item_name' => (string) $row->item_name,
                'semen_type' => $row->semen_type,
                'sire_name' => $row->sire_name,
                'semen_lot_id' => $row->semen_lot_id !== null ? (int) $row->semen_lot_id : null,
                'lot_code' => $row->lot_code,
                'straws_used' => (int) $row->straws_used,
                'warehouse_id' => (int) $row->warehouse_id,
                'warehouse_code' => $row->warehouse_code,
                'warehouse_name' => $row->warehouse_name,
                'document_id' => $row->document_id !== null ? (int) $row->document_id : null,
                'document_number' => $row->document_number !== null ? (int) $row->document_number : null,
                'notes' => $row->notes,
                'voided_at' => $row->voided_at,
                'void_reason' => $row->void_reason,
            ])
            ->all();
    }

    public function committedStraws(array $itemIds, int $companyId): array
    {
        if ($itemIds === []) {
            return [];
        }

        return DB::table('service_order_females as f')
            ->join('service_orders as o', 'o.id', '=', 'f.service_order_id')
            ->where('o.company_id', $companyId)
            ->where('o.service_method', ServiceMethod::AI->value)
            ->where('o.status', ServiceOrderStatus::APPROVED->value)
            ->whereIn('f.planned_semen_item_id', $itemIds)
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))
                ->from('service_order_inseminations as i')
                ->whereColumn('i.service_order_id', 'f.service_order_id')
                ->whereColumn('i.female_caravan_id', 'f.female_caravan_id')
                ->whereNull('i.voided_at'))
            ->groupBy('f.planned_semen_item_id')
            ->selectRaw('f.planned_semen_item_id as item_id, COUNT(*) as straws')
            ->pluck('straws', 'item_id')
            ->map(fn ($straws) => (int) $straws)
            ->all();
    }

    public function search(InseminationFilters $filters, int $companyId): array
    {
        $query = DB::table('service_order_inseminations as i')
            ->join('caravans as c', 'c.id', '=', 'i.female_caravan_id')
            ->leftJoin('batches as cb', 'cb.id', '=', 'c.batch_id')
            ->join('service_orders as o', 'o.id', '=', 'i.service_order_id')
            ->leftJoin('batches as ob', 'ob.id', '=', 'o.batch_id')
            ->join('items as it', 'it.id', '=', 'i.item_id')
            ->leftJoin('item_semen_details as d', 'd.item_id', '=', 'i.item_id')
            ->leftJoin('semen_sires as s', 's.id', '=', 'd.semen_sire_id')
            ->leftJoin('semen_lots as l', 'l.id', '=', 'i.semen_lot_id')
            ->join('warehouses as w', 'w.id', '=', 'i.warehouse_id')
            ->leftJoin('item_document_lines as dl', 'dl.id', '=', 'i.item_document_line_id')
            ->leftJoin('item_documents as doc', 'doc.id', '=', 'dl.item_document_id')
            ->where('i.company_id', $companyId)
            ->when(!$filters->includeVoided, fn ($q) => $q->whereNull('i.voided_at'))
            ->when($filters->itemId !== null, fn ($q) => $q->where('i.item_id', $filters->itemId))
            ->when($filters->semenSireId !== null, fn ($q) => $q->where('d.semen_sire_id', $filters->semenSireId))
            ->when($filters->semenLotId !== null, fn ($q) => $q->where('i.semen_lot_id', $filters->semenLotId))
            ->when($filters->serviceOrderId !== null, fn ($q) => $q->where('i.service_order_id', $filters->serviceOrderId))
            ->when($filters->batchId !== null, fn ($q) => $q->where('o.batch_id', $filters->batchId))
            ->when($filters->caravan !== null, fn ($q) => $q->where('c.identification', 'like', '%' . $filters->caravan . '%'))
            ->when($filters->from !== null, fn ($q) => $q->where('i.inseminated_on', '>=', $filters->from))
            ->when($filters->to !== null, fn ($q) => $q->where('i.inseminated_on', '<=', $filters->to));

        $totals = (clone $query)->selectRaw('COUNT(*) as total, COUNT(DISTINCT i.female_caravan_id) as females, COALESCE(SUM(i.straws_used), 0) as straws')->first();

        $rows = $query
            ->orderByDesc('i.inseminated_on')
            ->orderByDesc('i.id')
            ->forPage($filters->page, $filters->perPage)
            ->get([
                'i.id', 'i.female_caravan_id', 'c.identification', 'c.batch_id as current_batch_id', 'cb.name as current_batch_name',
                'i.service_order_id', 'o.code as order_code', 'o.batch_id as order_batch_id', 'ob.name as order_batch_name',
                'i.inseminated_on', 'i.item_id', 'it.name as item_name', 'd.semen_type', 'd.semen_sire_id', 's.name as sire_name',
                'i.semen_lot_id', 'l.code as lot_code', 'i.straws_used', 'i.warehouse_id', 'w.code as warehouse_code',
                'doc.id as document_id', 'doc.number as document_number', 'i.voided_at', 'i.void_reason',
            ]);

        return [
            'data' => $rows->map(fn (object $row) => [
                'id' => (int) $row->id,
                'female_caravan_id' => (int) $row->female_caravan_id,
                'identification' => (string) $row->identification,
                'current_batch' => $row->current_batch_id === null ? null : ['id' => (int) $row->current_batch_id, 'name' => $row->current_batch_name],
                'service_order' => ['id' => (int) $row->service_order_id, 'code' => $row->order_code],
                'order_batch' => $row->order_batch_id === null ? null : ['id' => (int) $row->order_batch_id, 'name' => $row->order_batch_name],
                'inseminated_on' => substr((string) $row->inseminated_on, 0, 10),
                'item' => ['id' => (int) $row->item_id, 'name' => $row->item_name],
                'semen_type' => $row->semen_type,
                'sire' => $row->semen_sire_id === null ? null : ['id' => (int) $row->semen_sire_id, 'name' => $row->sire_name],
                'semen_lot' => $row->semen_lot_id === null ? null : ['id' => (int) $row->semen_lot_id, 'code' => $row->lot_code],
                'straws_used' => (int) $row->straws_used,
                'warehouse' => ['id' => (int) $row->warehouse_id, 'code' => $row->warehouse_code],
                'document' => $row->document_id === null ? null : ['id' => (int) $row->document_id, 'number' => (int) $row->document_number],
                'voided_at' => $row->voided_at,
                'void_reason' => $row->void_reason,
            ])->all(),
            'total' => (int) ($totals->total ?? 0),
            'females' => (int) ($totals->females ?? 0),
            'straws' => (int) ($totals->straws ?? 0),
        ];
    }

    public function documentIdOfLine(int $lineId, int $companyId): ?int
    {
        $id = DB::table('item_document_lines')
            ->where('id', $lineId)
            ->where('company_id', $companyId)
            ->value('item_document_id');

        return $id === null ? null : (int) $id;
    }

    public function tankBalances(array $itemIds, int $companyId): array
    {
        if ($itemIds === []) {
            return [];
        }

        return DB::table('item_stocks as s')
            ->join('warehouses as w', 'w.id', '=', 's.warehouse_id')
            ->where('s.company_id', $companyId)
            ->where('w.kind', 'CRYO_TANK')
            ->whereIn('s.item_id', $itemIds)
            ->groupBy('s.item_id')
            ->selectRaw('s.item_id, SUM(s.quantity) as balance')
            ->pluck('balance', 'item_id')
            ->map(fn ($balance) => (string) $balance)
            ->all();
    }

    private static function toEntity(ServiceOrderInsemination $model): ServiceOrderInseminationEntity
    {
        return new ServiceOrderInseminationEntity(
            (int) $model->id,
            (int) $model->company_id,
            (int) $model->service_order_id,
            (int) $model->female_caravan_id,
            $model->inseminated_on->format('Y-m-d'),
            (int) $model->item_id,
            $model->semen_lot_id,
            (int) $model->straws_used,
            (int) $model->warehouse_id,
            $model->item_document_line_id,
            $model->notes,
            $model->voided_at?->toDateTimeString(),
            $model->void_reason,
            $model->created_by,
            $model->voided_by,
        );
    }
}
