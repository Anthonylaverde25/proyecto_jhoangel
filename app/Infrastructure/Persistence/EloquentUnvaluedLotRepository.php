<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Mappers\DecimalColumn;
use App\Core\Entities\UnvaluedIssueEntity;
use App\Core\Entities\UnvaluedLotEntity;
use App\Core\Enums\UnvaluedLotStatus;
use App\Core\Interfaces\IUnvaluedLotRepository;
use App\Models\StockUnvaluedIssue;
use App\Models\StockUnvaluedLot;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Every query filters by company explicitly (D15, layer 2). */
class EloquentUnvaluedLotRepository implements IUnvaluedLotRepository
{
    public function create(UnvaluedLotEntity $lot): UnvaluedLotEntity
    {
        $model = StockUnvaluedLot::withoutGlobalScopes()->create([
            'company_id' => $lot->getCompanyId(),
            ...$this->columns($lot),
            'warehouse_id' => $lot->getWarehouseId(),
            'item_id' => $lot->getItemId(),
            'delivery_note_line_id' => $lot->getDeliveryNoteLineId(),
            'receipt_line_id' => $lot->getReceiptLineId(),
            'quantity' => $lot->getQuantity(),
        ]);
        $lot->assignId((int) $model->id);

        return $lot;
    }

    public function save(UnvaluedLotEntity $lot): void
    {
        StockUnvaluedLot::withoutGlobalScopes()
            ->where('company_id', $lot->getCompanyId())
            ->whereKey($lot->getId())
            ->update([...$this->columns($lot), 'updated_at' => now()]);
    }

    public function lockPending(int $warehouseId, int $itemId, int $companyId): array
    {
        return $this->query($companyId)
            ->where('warehouse_id', $warehouseId)
            ->where('item_id', $itemId)
            ->where('status', UnvaluedLotStatus::PENDING->value)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->map(fn (StockUnvaluedLot $m) => self::toEntity($m))
            ->all();
    }

    public function lockByDeliveryNoteLines(array $deliveryNoteLineIds, int $companyId): array
    {
        if ($deliveryNoteLineIds === []) {
            return [];
        }

        $lots = [];
        foreach ($this->query($companyId)->whereIn('delivery_note_line_id', $deliveryNoteLineIds)->orderBy('id')->lockForUpdate()->get() as $model) {
            $lots[(int) $model->delivery_note_line_id] = self::toEntity($model);
        }

        return $lots;
    }

    public function lockValuedBy(array $invoiceLineIds, int $companyId): array
    {
        if ($invoiceLineIds === []) {
            return [];
        }

        return $this->query($companyId)
            ->whereIn('valued_by_line_id', $invoiceLineIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->map(fn (StockUnvaluedLot $m) => self::toEntity($m))
            ->all();
    }

    public function lockByIds(array $ids, int $companyId): array
    {
        if ($ids === []) {
            return [];
        }

        $lots = [];
        foreach ($this->query($companyId)->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get() as $model) {
            $lots[(int) $model->id] = self::toEntity($model);
        }

        return $lots;
    }

    public function openIssueDocumentNumbers(int $lotId, int $companyId): array
    {
        return DB::table('stock_unvalued_issues as i')
            ->join('item_document_lines as l', 'l.id', '=', 'i.item_document_line_id')
            ->join('item_documents as d', 'd.id', '=', 'l.item_document_id')
            ->where('i.company_id', $companyId)
            ->where('i.lot_id', $lotId)
            ->whereNull('i.reversed_by_line_id')
            ->orderBy('d.number')
            ->distinct()
            ->pluck('d.number')
            ->map(fn ($number) => (int) $number)
            ->all();
    }

    public function pendingOfItem(int $itemId, int $companyId): array
    {
        return DB::table('stock_unvalued_lots as s')
            ->join('fiscal_document_lines as l', 'l.id', '=', 's.delivery_note_line_id')
            ->join('fiscal_documents as f', 'f.id', '=', 'l.fiscal_document_id')
            ->join('fiscal_document_types as t', 't.id', '=', 'f.fiscal_document_type_id')
            ->where('s.company_id', $companyId)
            ->where('s.item_id', $itemId)
            ->where('s.status', UnvaluedLotStatus::PENDING->value)
            ->orderBy('s.id')
            ->get(['s.warehouse_id', 's.quantity', 's.remaining_quantity', 'f.id as document_id', 'f.issued_at', 'f.point_of_sale', 'f.number', 'f.reference', 't.name as type_name'])
            ->map(fn (object $row) => [
                'warehouse_id' => (int) $row->warehouse_id,
                'delivery_note_id' => (int) $row->document_id,
                'delivery_note_label' => $row->type_name . ' ' . \App\Core\Entities\FiscalDocumentEntity::formatNumber(
                    $row->point_of_sale === null ? null : (int) $row->point_of_sale,
                    $row->number === null ? null : (int) $row->number,
                    $row->reference,
                ),
                'issued_at' => substr((string) $row->issued_at, 0, 10),
                'quantity' => DecimalColumn::read($row->quantity, 4),
                'remaining_quantity' => DecimalColumn::read($row->remaining_quantity, 4),
                'consumed_quantity' => bcsub(DecimalColumn::read($row->quantity, 4), DecimalColumn::read($row->remaining_quantity, 4), 4),
            ])
            ->all();
    }

    public function createIssues(array $issues): void
    {
        $now = now();
        foreach ($issues as $issue) {
            DB::table('stock_unvalued_issues')->insert([
                'company_id' => $issue->companyId,
                'lot_id' => $issue->lotId,
                'item_document_line_id' => $issue->itemDocumentLineId,
                'quantity' => $issue->quantity,
                'unit_cost' => $issue->unitCost,
                'total_cost' => $issue->totalCost,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function openIssuesOfLine(int $itemDocumentLineId, int $companyId): array
    {
        return StockUnvaluedIssue::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('item_document_line_id', $itemDocumentLineId)
            ->whereNull('reversed_by_line_id')
            ->orderBy('id')
            ->get()
            ->map(fn (StockUnvaluedIssue $m) => new UnvaluedIssueEntity(
                (int) $m->id,
                (int) $m->company_id,
                (int) $m->lot_id,
                (int) $m->item_document_line_id,
                DecimalColumn::read($m->quantity, 4),
                DecimalColumn::readNullable($m->unit_cost, 6),
                DecimalColumn::readNullable($m->total_cost, 4),
            ))
            ->all();
    }

    public function markIssueReversed(int $issueId, int $reversalLineId, int $companyId): void
    {
        StockUnvaluedIssue::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereKey($issueId)
            ->update(['reversed_by_line_id' => $reversalLineId, 'updated_at' => now()]);
    }

    public function setIssueCosts(int $lotId, ?string $unitCost, int $companyId): void
    {
        $issues = StockUnvaluedIssue::withoutGlobalScopes()->where('company_id', $companyId)->where('lot_id', $lotId)->get();
        foreach ($issues as $issue) {
            $issue->update([
                'unit_cost' => $unitCost,
                'total_cost' => $unitCost === null ? null : bcmul(DecimalColumn::read($issue->quantity, 4), $unitCost, 4),
            ]);
        }
    }

    public static function toEntity(StockUnvaluedLot $model): UnvaluedLotEntity
    {
        return new UnvaluedLotEntity(
            (int) $model->id,
            (int) $model->company_id,
            (int) $model->warehouse_id,
            (int) $model->item_id,
            (int) $model->delivery_note_line_id,
            (int) $model->receipt_line_id,
            DecimalColumn::read($model->quantity, 4),
            DecimalColumn::read($model->remaining_quantity, 4),
            UnvaluedLotStatus::from($model->status),
            DecimalColumn::readNullable($model->valued_unit_cost, 6),
            DecimalColumn::readNullable($model->valued_quantity, 4),
            $model->valued_by_line_id,
            $model->valuation_line_id,
            $model->valued_at?->toIso8601String(),
        );
    }

    /** @return array<string, mixed> the columns that change over the lot's life */
    private function columns(UnvaluedLotEntity $lot): array
    {
        return [
            'remaining_quantity' => $lot->getRemainingQuantity(),
            'status' => $lot->getStatus()->value,
            'valued_unit_cost' => $lot->getValuedUnitCost(),
            'valued_quantity' => $lot->getValuedQuantity(),
            'valued_by_line_id' => $lot->getValuedByLineId(),
            'valuation_line_id' => $lot->getValuationLineId(),
            'valued_at' => $lot->getValuedAt() === null ? null : \Illuminate\Support\Carbon::parse($lot->getValuedAt()),
        ];
    }

    private function query(int $companyId): Builder
    {
        return StockUnvaluedLot::withoutGlobalScopes()->where('company_id', $companyId);
    }
}
