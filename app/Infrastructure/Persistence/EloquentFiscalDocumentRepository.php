<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Mappers\DecimalColumn;
use App\Application\Mappers\FiscalDocumentMapper;
use App\Core\Entities\FiscalDocumentEntity;
use App\Core\Entities\FiscalDocumentTaxEntity;
use App\Core\Entities\TaxEntity;
use App\Core\Enums\FiscalDocumentKind;
use App\Core\Enums\FiscalDocumentStatus;
use App\Core\Interfaces\IFiscalDocumentRepository;
use App\Core\ValueObjects\FiscalDocumentFilters;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentLine;
use App\Models\FiscalDocumentLineTax;
use App\Models\FiscalDocumentTax;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Provider documents. Registered documents are never updated or deleted; voiding is the only change
 * (DS20). Every query filters by company explicitly (D15, layer 2).
 */
class EloquentFiscalDocumentRepository implements IFiscalDocumentRepository
{
    private const RELATIONS = [
        'type', 'provider', 'creator', 'related.type', 'stockOperation',
        'lines.item.unit', 'lines.inputUnit', 'lines.inputPresentation', 'lines.batch',
        'lines.taxes.tax', 'lines.taxes.taxRate', 'documentTaxes.tax',
        'invoicedBy.type', 'deliveryNotes.type', 'lines.deliveryNoteLine.document.type', 'lines.unvaluedLot',
    ];

    public function findByIdempotencyKey(string $key, int $companyId): ?FiscalDocumentEntity
    {
        $model = $this->query($companyId)->with(self::RELATIONS)->where('idempotency_key', $key)->first();

        return $model ? FiscalDocumentMapper::toEntity($model) : null;
    }

    public function findById(int $id, int $companyId): ?FiscalDocumentEntity
    {
        $model = $this->query($companyId)->with(self::RELATIONS)->find($id);

        return $model ? FiscalDocumentMapper::toEntity($model) : null;
    }

    public function findRegisteredDuplicate(int $companyId, int $providerId, int $typeId, int $pointOfSale, int $number): ?FiscalDocumentEntity
    {
        $model = $this->query($companyId)
            ->with(self::RELATIONS)
            ->where('provider_id', $providerId)
            ->where('fiscal_document_type_id', $typeId)
            ->where('point_of_sale', $pointOfSale)
            ->where('number', $number)
            ->where('status', FiscalDocumentStatus::REGISTERED->value)
            ->first();

        return $model ? FiscalDocumentMapper::toEntity($model) : null;
    }

    public function search(FiscalDocumentFilters $filters, int $companyId): array
    {
        $query = $this->filtered($filters, $companyId);

        $total = (clone $query)->count();
        $models = $query
            ->with(['type', 'provider', 'stockOperation', 'lines.item', 'related.type'])
            ->orderByDesc('issued_at')
            ->orderByDesc('id')
            ->forPage($filters->page, $filters->perPage)
            ->get();

        return ['data' => $models->map(fn (FiscalDocument $m) => FiscalDocumentMapper::toEntity($m))->all(), 'total' => $total];
    }

    public function summarize(FiscalDocumentFilters $filters, int $companyId): array
    {
        $byKind = $this->filtered($filters, $companyId, withKind: false)
            ->join('fiscal_document_types as t', 't.id', '=', 'fiscal_documents.fiscal_document_type_id')
            ->groupBy('t.kind')
            ->selectRaw('t.kind, COUNT(*) as n')
            ->pluck('n', 'kind')
            ->map(fn ($n) => (int) $n)
            ->all();
        $byStatus = $this->filtered($filters, $companyId, withStatus: false)
            ->groupBy('status')
            ->selectRaw('status, COUNT(*) as n')
            ->pluck('n', 'status')
            ->map(fn ($n) => (int) $n)
            ->all();

        // A delivery note carries no debt and its invoice repeats it: the period total leaves them out
        // unless the register is showing delivery notes.
        $totals = $this->filtered($filters, $companyId)
            ->join('fiscal_document_types as t', 't.id', '=', 'fiscal_documents.fiscal_document_type_id')
            ->when($filters->kind === null, fn ($q) => $q->where('t.kind', '!=', FiscalDocumentKind::DELIVERY_NOTE->value))
            // A delivery note's sign is 0 (no debt); listed alone, its amounts add up as they are.
            ->selectRaw('COUNT(*) as n,
                SUM((CASE WHEN t.sign = 0 THEN 1 ELSE t.sign END) * COALESCE(lines_amount, 0)) as net,
                SUM((CASE WHEN t.sign = 0 THEN 1 ELSE t.sign END) * (COALESCE(line_taxes_amount, 0) + COALESCE(document_taxes_amount, 0))) as taxes,
                SUM((CASE WHEN t.sign = 0 THEN 1 ELSE t.sign END) * COALESCE(total_amount, 0)) as total')
            ->first();

        return [
            'by_kind' => $byKind,
            'by_status' => $byStatus,
            'totals' => [
                'count' => (int) ($totals->n ?? 0),
                'net' => DecimalColumn::read($totals->net ?? null, 4),
                'taxes' => DecimalColumn::read($totals->taxes ?? null, 4),
                'total' => DecimalColumn::read($totals->total ?? null, 4),
            ],
        ];
    }

    /** The register's filters; the counts per kind and per status leave out their own filter. */
    private function filtered(FiscalDocumentFilters $filters, int $companyId, bool $withKind = true, bool $withStatus = true): Builder
    {
        return $this->query($companyId)
            ->when($withStatus, fn (Builder $q) => $q->where('status', $filters->status->value))
            ->when($filters->providerId !== null, fn (Builder $q) => $q->where('provider_id', $filters->providerId))
            ->when($withKind && $filters->kind !== null, fn (Builder $q) => $q->whereHas('type', fn (Builder $t) => $t->where('kind', $filters->kind->value)))
            ->when($filters->from !== null, fn (Builder $q) => $q->where('issued_at', '>=', $filters->from))
            ->when($filters->to !== null, fn (Builder $q) => $q->where('issued_at', '<=', $filters->to))
            ->when($filters->search !== null, function (Builder $q) use ($filters) {
                $term = '%' . $filters->search . '%';
                $digits = ltrim(preg_replace('/\D/', '', $filters->search) ?? '', '0');
                // CUITs are stored as digits: "30-70845123" has to find "3070845123…".
                $cuitTerm = preg_match('/^[\d\s-]+$/', $filters->search) === 1
                    ? '%' . preg_replace('/\D/', '', $filters->search) . '%'
                    : $term;
                $q->where(function (Builder $w) use ($term, $cuitTerm, $digits) {
                    $w->whereHas('provider', fn (Builder $p) => $p->withoutGlobalScopes()->where(
                        fn (Builder $n) => $n->where('name', 'like', $term)->orWhere('cuit', 'like', $cuitTerm)
                    ))
                        ->orWhere('reference', 'like', $term);
                    if ($digits !== '') {
                        $w->orWhere('number', (int) $digits);
                    }
                });
            });
    }

    public function create(FiscalDocumentEntity $document): FiscalDocumentEntity
    {
        $companyId = $document->getCompanyId();
        $model = FiscalDocument::withoutGlobalScopes()->create([
            'company_id' => $companyId,
            'provider_id' => $document->getProviderId(),
            'fiscal_document_type_id' => $document->getType()->id,
            'point_of_sale' => $document->getPointOfSale(),
            'number' => $document->getNumber(),
            'reference' => $document->getReference(),
            'issued_at' => $document->getIssuedAt(),
            'related_fiscal_document_id' => $document->getRelatedFiscalDocumentId(),
            'lines_amount' => $document->getLinesAmount(),
            'line_taxes_amount' => $document->getLineTaxesAmount(),
            'document_taxes_amount' => $document->getDocumentTaxesAmount(),
            'total_amount' => $document->getTotalAmount(),
            'declared_total' => $document->getDeclaredTotal(),
            'status' => FiscalDocumentStatus::REGISTERED->value,
            'notes' => $document->getNotes(),
            'idempotency_key' => $document->getIdempotencyKey(),
            'created_by' => $document->getCreatedBy(),
            'is_valued' => $document->isValued(),
        ]);

        foreach ($document->getLines() as $line) {
            $lineModel = FiscalDocumentLine::withoutGlobalScopes()->create([
                'company_id' => $companyId,
                'fiscal_document_id' => $model->id,
                'position' => $line->position,
                'item_id' => $line->itemId,
                'input_quantity' => $line->inputQuantity,
                'input_unit_id' => $line->inputUnitId,
                'input_presentation_id' => $line->inputPresentationId,
                'quantity' => $line->quantity,
                'unit_price' => $line->unitPrice,
                'line_amount' => $line->lineAmount,
                'non_recoverable_tax_amount' => $line->nonRecoverableTaxAmount,
                'cost_amount' => $line->costAmount,
                'unit_cost' => $line->unitCost,
                'batch_id' => $line->batchId,
                'heads_treated' => $line->headsTreated,
                'delivery_note_line_id' => $line->deliveryNoteLineId,
            ]);
            foreach ($line->taxes as $tax) {
                FiscalDocumentLineTax::withoutGlobalScopes()->create(
                    ['company_id' => $companyId, 'fiscal_document_line_id' => $lineModel->id, 'tax_rate_id' => $tax->taxRateId] + $this->taxColumns($tax)
                );
            }
        }
        foreach ($document->getDocumentTaxes() as $tax) {
            FiscalDocumentTax::withoutGlobalScopes()->create(
                ['company_id' => $companyId, 'fiscal_document_id' => $model->id] + $this->taxColumns($tax)
            );
        }

        return $this->findById((int) $model->id, $companyId);
    }

    public function markVoided(FiscalDocumentEntity $document, ?int $userId): void
    {
        $this->query($document->getCompanyId())
            ->whereKey($document->getId())
            ->where('status', FiscalDocumentStatus::REGISTERED->value)
            ->update([
                'status' => FiscalDocumentStatus::VOIDED->value,
                'void_reason' => $document->getVoidReason(),
                'voided_by' => $userId,
                'voided_at' => now(),
                'updated_at' => now(),
            ]);
    }

    public function lockDeliveryNotes(array $ids, int $companyId): array
    {
        if ($ids === []) {
            return [];
        }

        $locked = $this->query($companyId)->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->pluck('id')->all();

        return $this->query($companyId)
            ->with(self::RELATIONS)
            ->whereIn('id', $locked)
            ->orderBy('id')
            ->get()
            ->map(fn (FiscalDocument $m) => FiscalDocumentMapper::toEntity($m))
            ->all();
    }

    public function markInvoiced(array $ids, ?int $invoiceId, int $companyId): void
    {
        if ($ids === []) {
            return;
        }

        $this->query($companyId)->whereIn('id', $ids)->update(['invoiced_by_document_id' => $invoiceId, 'updated_at' => now()]);
    }

    public function deliveryNoteIdsOf(int $invoiceId, int $companyId): array
    {
        return $this->query($companyId)->where('invoiced_by_document_id', $invoiceId)->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public function pendingDeliveryNotes(?int $providerId, int $companyId): array
    {
        $notes = $this->query($companyId)
            ->with(['type', 'provider'])
            ->withCount('lines')
            ->whereHas('type', fn (Builder $t) => $t->where('kind', FiscalDocumentKind::DELIVERY_NOTE->value))
            ->where('status', FiscalDocumentStatus::REGISTERED->value)
            ->whereNull('invoiced_by_document_id')
            ->when($providerId !== null, fn (Builder $q) => $q->where('provider_id', $providerId))
            ->orderBy('issued_at')
            ->orderBy('id')
            ->get();

        $lots = DB::table('stock_unvalued_lots as s')
            ->join('fiscal_document_lines as l', 'l.id', '=', 's.delivery_note_line_id')
            ->where('s.company_id', $companyId)
            ->whereIn('l.fiscal_document_id', $notes->pluck('id')->all() ?: [0])
            ->groupBy('l.fiscal_document_id')
            ->selectRaw('l.fiscal_document_id, SUM(s.remaining_quantity) as pending, SUM(s.quantity - s.remaining_quantity) as issued, SUM(CASE WHEN s.remaining_quantity < s.quantity THEN 1 ELSE 0 END) as lines_with_issues')
            ->get()
            ->keyBy('fiscal_document_id');

        $today = now()->startOfDay();

        return $notes->map(function (FiscalDocument $note) use ($lots, $today) {
            $lot = $lots->get($note->id);

            return [
                'id' => (int) $note->id,
                'label' => FiscalDocumentMapper::label($note),
                'provider_id' => (int) $note->provider_id,
                'provider_name' => $note->provider?->name,
                'issued_at' => $note->issued_at->format('Y-m-d'),
                'age_days' => (int) $note->issued_at->copy()->startOfDay()->diffInDays($today),
                'is_valued' => (bool) $note->is_valued,
                'lines_count' => (int) $note->lines_count,
                'lines_amount' => DecimalColumn::readNullable($note->lines_amount, 4),
                'unvalued_pending' => $lot === null ? null : DecimalColumn::read($lot->pending, 4),
                'unvalued_issued' => $lot === null ? null : DecimalColumn::read($lot->issued, 4),
                'lines_with_issues' => $lot === null ? 0 : (int) $lot->lines_with_issues,
            ];
        })->all();
    }

    public function lastPurchasesByProvider(int $itemId, int $companyId): array
    {
        $rows = DB::table('fiscal_document_lines as l')
            ->join('fiscal_documents as f', 'f.id', '=', 'l.fiscal_document_id')
            ->join('fiscal_document_types as t', 't.id', '=', 'f.fiscal_document_type_id')
            ->leftJoin('item_presentations as p', 'p.id', '=', 'l.input_presentation_id')
            ->leftJoin('units_of_measure as u', 'u.id', '=', 'l.input_unit_id')
            ->where('f.company_id', $companyId)
            ->where('l.item_id', $itemId)
            ->where('f.status', FiscalDocumentStatus::REGISTERED->value)
            ->whereIn('t.kind', [FiscalDocumentKind::INVOICE->value, FiscalDocumentKind::TICKET->value])
            ->orderByDesc('f.issued_at')
            ->orderByDesc('l.id')
            ->get(['f.id', 'f.provider_id', 'f.issued_at', 'f.point_of_sale', 'f.number', 'f.reference', 't.name as type_name', 'l.unit_price', 'p.name as presentation', 'u.symbol']);

        $last = [];
        foreach ($rows as $row) {
            $providerId = (int) $row->provider_id;
            if (isset($last[$providerId])) {
                continue;
            }
            $last[$providerId] = [
                'fiscal_document_id' => (int) $row->id,
                'label' => $row->type_name . ' ' . FiscalDocumentEntity::formatNumber(
                    $row->point_of_sale === null ? null : (int) $row->point_of_sale,
                    $row->number === null ? null : (int) $row->number,
                    $row->reference,
                ),
                'issued_at' => substr((string) $row->issued_at, 0, 10),
                'unit_price' => DecimalColumn::read($row->unit_price, 6),
                'input_label' => (string) ($row->presentation ?? $row->symbol),
            ];
        }

        return $last;
    }

    public function lastVatRateIds(array $itemIds, int $companyId): array
    {
        if ($itemIds === []) {
            return [];
        }

        $rows = DB::table('fiscal_document_line_taxes as lt')
            ->join('taxes as tx', 'tx.id', '=', 'lt.tax_id')
            ->join('fiscal_document_lines as l', 'l.id', '=', 'lt.fiscal_document_line_id')
            ->join('fiscal_documents as f', 'f.id', '=', 'l.fiscal_document_id')
            ->where('lt.company_id', $companyId)
            ->where('tx.code', TaxEntity::VAT)
            ->where('f.status', FiscalDocumentStatus::REGISTERED->value)
            ->whereIn('l.item_id', $itemIds)
            ->whereNotNull('lt.tax_rate_id')
            ->orderByDesc('f.issued_at')
            ->orderByDesc('l.id')
            ->get(['l.item_id', 'lt.tax_rate_id']);

        $rates = [];
        foreach ($rows as $row) {
            $rates[(int) $row->item_id] ??= (int) $row->tax_rate_id;
        }

        return $rates;
    }

    /** @return array<string, mixed> */
    private function taxColumns(FiscalDocumentTaxEntity $tax): array
    {
        return [
            'tax_id' => $tax->taxId,
            'rate' => $tax->rate,
            'taxable_base' => $tax->taxableBase,
            'amount' => $tax->amount,
            'is_recoverable' => $tax->isRecoverable,
        ];
    }

    private function query(int $companyId): Builder
    {
        return FiscalDocument::withoutGlobalScopes()->where('company_id', $companyId);
    }
}
