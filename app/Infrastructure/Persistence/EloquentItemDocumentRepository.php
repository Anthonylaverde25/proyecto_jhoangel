<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Mappers\DecimalColumn;
use App\Application\Mappers\StockMapper;
use App\Core\Entities\FiscalDocumentEntity;
use App\Core\Entities\ItemDocumentEntity;
use App\Core\Entities\ItemDocumentLineEntity;
use App\Core\Enums\FiscalDocumentStatus;
use App\Core\Enums\ItemDocumentType;
use App\Core\Interfaces\IItemDocumentRepository;
use App\Core\ValueObjects\ItemDocumentFilters;
use App\Models\ItemDocument;
use App\Models\ItemDocumentLine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Stock operations. Append-only (I3): there is no update or delete. Every query filters by company
 * explicitly (D15, layer 2).
 */
class EloquentItemDocumentRepository implements IItemDocumentRepository
{
    private const RELATIONS = [
        'lines.item.unit', 'lines.inputUnit', 'lines.inputPresentation', 'lines.movement',
        'warehouse', 'fiscalDocument.type', 'batch', 'reverses', 'reversedBy', 'creator',
    ];

    public function findByIdempotencyKey(string $key, int $companyId): ?ItemDocumentEntity
    {
        $model = $this->query($companyId)->with(self::RELATIONS)->where('idempotency_key', $key)->first();

        return $model ? StockMapper::documentToEntity($model) : null;
    }

    public function findById(int $id, int $companyId): ?ItemDocumentEntity
    {
        $model = $this->query($companyId)->with(self::RELATIONS)->find($id);

        return $model ? StockMapper::documentToEntity($model) : null;
    }

    public function findByFiscalDocument(int $fiscalDocumentId, int $companyId): ?ItemDocumentEntity
    {
        $model = $this->query($companyId)
            ->with(self::RELATIONS)
            ->where('fiscal_document_id', $fiscalDocumentId)
            ->where('type', '!=', ItemDocumentType::REVERSAL->value)
            ->first();

        return $model ? StockMapper::documentToEntity($model) : null;
    }

    public function search(ItemDocumentFilters $filters, int $companyId): array
    {
        $query = $this->query($companyId)
            ->when($filters->type !== null, fn (Builder $q) => $q->where('type', $filters->type->value))
            ->when($filters->batchId !== null, fn (Builder $q) => $q->where('batch_id', $filters->batchId))
            ->when($filters->itemId !== null, fn (Builder $q) => $q->whereHas(
                'lines',
                fn (Builder $l) => $l->withoutGlobalScopes()->where('item_id', $filters->itemId)
            ))
            ->when($filters->from !== null, fn (Builder $q) => $q->where('occurred_at', '>=', $filters->from . ' 00:00:00'))
            ->when($filters->to !== null, fn (Builder $q) => $q->where('occurred_at', '<=', $filters->to . ' 23:59:59'));

        $total = (clone $query)->count();
        $models = $query
            ->with(['lines.item.unit', 'warehouse', 'fiscalDocument.type', 'batch', 'reverses', 'reversedBy'])
            ->orderByDesc('id')
            ->forPage($filters->page, $filters->perPage)
            ->get();

        return ['data' => $models->map(fn (ItemDocument $m) => StockMapper::documentToEntity($m))->all(), 'total' => $total];
    }

    public function nextNumber(int $companyId): int
    {
        return (int) $this->query($companyId)->max('number') + 1;
    }

    public function create(ItemDocumentEntity $document): ItemDocumentEntity
    {
        $model = ItemDocument::withoutGlobalScopes()->create([
            'company_id' => $document->getCompanyId(),
            'number' => $document->getNumber(),
            'type' => $document->getType()->value,
            'warehouse_id' => $document->getWarehouseId(),
            'fiscal_document_id' => $document->getFiscalDocumentId(),
            'batch_id' => $document->getBatchId(),
            'heads_treated' => $document->getHeadsTreated(),
            'occurred_at' => $document->getOccurredAt(),
            'reverses_document_id' => $document->getReversesDocumentId(),
            'reason' => $document->getReason(),
            'notes' => $document->getNotes(),
            'idempotency_key' => $document->getIdempotencyKey(),
            'created_by' => $document->getCreatedBy(),
        ]);

        foreach ($document->getLines() as $line) {
            /** @var ItemDocumentLineEntity $line */
            ItemDocumentLine::withoutGlobalScopes()->create([
                'company_id' => $document->getCompanyId(),
                'item_document_id' => $model->id,
                'position' => $line->getPosition(),
                'item_id' => $line->getItemId(),
                'fiscal_document_line_id' => $line->getFiscalDocumentLineId(),
                'input_quantity' => $line->getInputQuantity(),
                'input_unit_id' => $line->getInputUnitId(),
                'input_presentation_id' => $line->getInputPresentationId(),
                'quantity' => $line->getQuantity(),
                'input_unit_price' => $line->getInputUnitPrice(),
                // Fully unvalued, it has no unit cost yet (delivery C); otherwise as valued by the ledger.
                'unit_cost' => $line->isFullyUnvalued() ? null : $line->getUnitCost(),
                'total_cost' => $line->getTotalCost(),
                'unvalued_quantity' => $line->getUnvaluedQuantity(),
                'unabsorbed_cost' => $line->getUnabsorbedCost(),
                'counted_quantity' => $line->getCountedQuantity(),
                'reverses_line_id' => $line->getReversesLineId(),
            ]);
        }

        return $this->findById((int) $model->id, $document->getCompanyId());
    }

    public function batchHistory(int $batchId, int $companyId): array
    {
        $roots = $this->rootCategories($companyId);

        // Units taken from unvalued lots (delivery C): their cost counts once the invoice values them.
        $issues = DB::table('stock_unvalued_issues')
            ->where('company_id', $companyId)
            ->whereNull('reversed_by_line_id')
            ->groupBy('item_document_line_id')
            ->selectRaw('item_document_line_id, COALESCE(SUM(total_cost), 0) as valued_cost, SUM(CASE WHEN unit_cost IS NULL THEN quantity ELSE 0 END) as pending_quantity');

        $consumptions = DB::table('item_document_lines as l')
            ->join('item_documents as d', 'd.id', '=', 'l.item_document_id')
            ->leftJoinSub($issues, 'iss', 'iss.item_document_line_id', '=', 'l.id')
            ->join('items as i', 'i.id', '=', 'l.item_id')
            ->join('units_of_measure as u', 'u.id', '=', 'i.unit_of_measure_id')
            ->leftJoin('item_documents as r', 'r.reverses_document_id', '=', 'd.id')
            ->where('d.company_id', $companyId)
            ->where('d.batch_id', $batchId)
            ->where('d.type', ItemDocumentType::CONSUMPTION->value)
            ->get([
                'l.id', 'd.id as document_id', 'd.number', 'd.occurred_at', 'd.heads_treated', 'i.id as item_id', 'i.name as item_name',
                'i.type as item_type', 'i.track_stock', 'i.item_category_id', 'u.symbol', 'l.quantity', 'l.total_cost',
                'r.id as reversal_id', 'r.number as reversal_number', 'iss.valued_cost', 'iss.pending_quantity',
            ])
            ->map(fn (object $row) => [
                'source' => 'CONSUMPTION',
                'line_id' => (int) $row->id,
                'occurred_at' => substr((string) $row->occurred_at, 0, 10),
                'item_id' => (int) $row->item_id,
                'item_name' => $row->item_name,
                'behavior' => $this->behavior($row->item_type, (bool) $row->track_stock),
                'category' => $roots[(int) $row->item_category_id] ?? null,
                'quantity' => DecimalColumn::read($row->quantity, 4),
                'unit_symbol' => $row->symbol,
                'heads_treated' => (int) $row->heads_treated,
                'cost' => bcadd(DecimalColumn::read($row->total_cost, 4), DecimalColumn::read($row->valued_cost ?? '0', 4), 4),
                'pending_cost_quantity' => DecimalColumn::read($row->pending_quantity ?? '0', 4),
                'reference' => ['kind' => 'item_document', 'id' => (int) $row->document_id, 'label' => "Consumo N.º {$row->number}"],
                'voided' => $row->reversal_id === null ? null : "Anulado por el movimiento N.º {$row->reversal_number}",
            ]);

        $services = DB::table('fiscal_document_lines as l')
            ->join('fiscal_documents as f', 'f.id', '=', 'l.fiscal_document_id')
            ->join('fiscal_document_types as t', 't.id', '=', 'f.fiscal_document_type_id')
            ->join('items as i', 'i.id', '=', 'l.item_id')
            ->join('units_of_measure as u', 'u.id', '=', 'i.unit_of_measure_id')
            ->where('f.company_id', $companyId)
            ->where('l.batch_id', $batchId)
            ->get([
                'l.id', 'f.id as document_id', 'f.issued_at', 'f.point_of_sale', 'f.number', 'f.reference', 'f.status', 't.name as type_name',
                't.sign', 'l.heads_treated', 'i.id as item_id', 'i.name as item_name', 'i.type as item_type', 'i.track_stock',
                'i.item_category_id', 'u.symbol', 'l.quantity', 'l.cost_amount',
            ])
            ->map(function (object $row) use ($roots) {
                $cost = DecimalColumn::read($row->cost_amount, 4);

                return [
                    'source' => 'FISCAL_DOCUMENT',
                    'line_id' => (int) $row->id,
                    'occurred_at' => substr((string) $row->issued_at, 0, 10),
                    'item_id' => (int) $row->item_id,
                    'item_name' => $row->item_name,
                    'behavior' => $this->behavior($row->item_type, (bool) $row->track_stock),
                    'category' => $roots[(int) $row->item_category_id] ?? null,
                    'quantity' => DecimalColumn::read($row->quantity, 4),
                    'unit_symbol' => $row->symbol,
                    'heads_treated' => (int) $row->heads_treated,
                    // A credit note gives back what an invoice charged.
                    'cost' => (int) $row->sign < 0 ? bcmul($cost, '-1', 4) : $cost,
                    'pending_cost_quantity' => '0.0000',
                    'reference' => [
                        'kind' => 'fiscal_document',
                        'id' => (int) $row->document_id,
                        'label' => $row->type_name . ' ' . FiscalDocumentEntity::formatNumber(
                            $row->point_of_sale === null ? null : (int) $row->point_of_sale,
                            $row->number === null ? null : (int) $row->number,
                            $row->reference,
                        ),
                    ],
                    'voided' => $row->status === FiscalDocumentStatus::VOIDED->value ? 'Comprobante anulado' : null,
                ];
            });

        return $consumptions->concat($services)
            ->sortBy([['occurred_at', 'asc'], ['line_id', 'asc']])
            ->values()
            ->all();
    }

    private function behavior(string $type, bool $trackStock): string
    {
        return $type === 'SERVICE' ? 'SERVICE' : ($trackStock ? 'STOCKED_ARTICLE' : 'EXPENSE_ARTICLE');
    }

    /**
     * Name of the root of each category, for the per-category summary of the batch history.
     *
     * @return array<int, string>
     */
    private function rootCategories(int $companyId): array
    {
        $categories = DB::table('item_categories')->where('company_id', $companyId)->get(['id', 'parent_id', 'name'])->keyBy('id');
        $roots = [];
        foreach ($categories as $id => $category) {
            $current = $category;
            while ($current->parent_id !== null && isset($categories[$current->parent_id])) {
                $current = $categories[$current->parent_id];
            }
            $roots[(int) $id] = $current->name;
        }

        return $roots;
    }

    private function query(int $companyId): Builder
    {
        return ItemDocument::withoutGlobalScopes()->where('company_id', $companyId);
    }
}
