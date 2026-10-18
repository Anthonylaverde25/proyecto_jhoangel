<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Mappers\DecimalColumn;
use App\Application\Mappers\FiscalDocumentMapper;
use App\Core\Entities\FiscalDiscrepancyEntity;
use App\Core\Enums\DiscrepancyStatus;
use App\Core\Enums\DiscrepancyType;
use App\Core\Interfaces\IFiscalDiscrepancyRepository;
use App\Core\ValueObjects\DiscrepancyFilter;
use App\Models\FiscalDocumentDiscrepancy;
use Illuminate\Database\Eloquent\Builder;

/** Every query filters by company explicitly (D15, layer 2). */
class EloquentFiscalDiscrepancyRepository implements IFiscalDiscrepancyRepository
{
    private const RELATIONS = ['invoice.type', 'deliveryNote.type', 'item', 'resolver'];

    public function createMany(array $discrepancies): void
    {
        foreach ($discrepancies as $discrepancy) {
            FiscalDocumentDiscrepancy::withoutGlobalScopes()->create([
                'company_id' => $discrepancy->getCompanyId(),
                'invoice_document_id' => $discrepancy->getInvoiceDocumentId(),
                'delivery_note_id' => $discrepancy->getDeliveryNoteId(),
                'invoice_line_id' => $discrepancy->getInvoiceLineId(),
                'delivery_note_line_id' => $discrepancy->getDeliveryNoteLineId(),
                'item_id' => $discrepancy->getItemId(),
                'type' => $discrepancy->getType()->value,
                'expected_value' => $discrepancy->getExpectedValue(),
                'actual_value' => $discrepancy->getActualValue(),
                'detail' => $discrepancy->getDetail(),
                'status' => $discrepancy->getStatus()->value,
                'raised_by_user_id' => $discrepancy->getRaisedByUserId(),
            ]);
        }
    }

    public function search(DiscrepancyFilter $filter, int $companyId): array
    {
        return $this->query($companyId)
            ->with(self::RELATIONS)
            ->when($filter->status !== null, fn (Builder $q) => $q->where('status', $filter->status->value))
            ->when($filter->invoiceId !== null, fn (Builder $q) => $q->where('invoice_document_id', $filter->invoiceId))
            ->when($filter->deliveryNoteId !== null, fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('delivery_note_id', $filter->deliveryNoteId)
                // A total or an extra line belongs to the whole invoice, and so to each of its notes.
                ->orWhere(fn (Builder $o) => $o->whereNull('delivery_note_id')->whereIn('invoice_document_id', fn ($s) => $s
                    ->select('invoiced_by_document_id')->from('fiscal_documents')->where('id', $filter->deliveryNoteId)))))
            ->when($filter->providerId !== null, fn (Builder $q) => $q->whereHas('invoice', fn (Builder $i) => $i->where('provider_id', $filter->providerId)))
            ->orderByDesc('id')
            ->get()
            ->map(fn (FiscalDocumentDiscrepancy $m) => self::toEntity($m))
            ->all();
    }

    public function find(int $id, int $companyId): ?FiscalDiscrepancyEntity
    {
        $model = $this->query($companyId)->with(self::RELATIONS)->find($id);

        return $model ? self::toEntity($model) : null;
    }

    public function save(FiscalDiscrepancyEntity $discrepancy): void
    {
        $this->query($discrepancy->getCompanyId())
            ->whereKey($discrepancy->getId())
            ->update([
                'status' => $discrepancy->getStatus()->value,
                'resolution' => $discrepancy->getResolution(),
                'resolved_by_user_id' => $discrepancy->getResolvedByUserId(),
                'resolved_at' => $discrepancy->getResolvedAt() === null ? null : \Illuminate\Support\Carbon::parse($discrepancy->getResolvedAt()),
                'updated_at' => now(),
            ]);
    }

    public function cancelForInvoice(int $invoiceId, int $companyId): void
    {
        $this->query($companyId)
            ->where('invoice_document_id', $invoiceId)
            ->where('status', '!=', DiscrepancyStatus::CANCELLED->value)
            ->update(['status' => DiscrepancyStatus::CANCELLED->value, 'resolution' => null, 'resolved_at' => null, 'resolved_by_user_id' => null, 'updated_at' => now()]);
    }

    public function countsFor(int $documentId, int $companyId): array
    {
        $rows = $this->query($companyId)
            ->where(fn (Builder $q) => $q->where('invoice_document_id', $documentId)->orWhere('delivery_note_id', $documentId))
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'open' => (int) ($rows[DiscrepancyStatus::OPEN->value] ?? 0),
            'resolved' => (int) ($rows[DiscrepancyStatus::RESOLVED->value] ?? 0),
            'cancelled' => (int) ($rows[DiscrepancyStatus::CANCELLED->value] ?? 0),
        ];
    }

    private static function toEntity(FiscalDocumentDiscrepancy $model): FiscalDiscrepancyEntity
    {
        return new FiscalDiscrepancyEntity(
            (int) $model->id,
            (int) $model->company_id,
            (int) $model->invoice_document_id,
            $model->delivery_note_id,
            $model->invoice_line_id,
            $model->delivery_note_line_id,
            $model->item_id,
            DiscrepancyType::from($model->type),
            DecimalColumn::readNullable($model->expected_value, 6),
            DecimalColumn::readNullable($model->actual_value, 6),
            $model->detail,
            DiscrepancyStatus::from($model->status),
            $model->resolution,
            $model->resolved_by_user_id,
            $model->resolved_at?->toIso8601String(),
            $model->raised_by_user_id,
            $model->created_at?->toIso8601String(),
            $model->invoice === null ? null : FiscalDocumentMapper::label($model->invoice),
            $model->deliveryNote === null ? null : FiscalDocumentMapper::label($model->deliveryNote),
            $model->item?->name,
            $model->resolver?->name,
        );
    }

    private function query(int $companyId): Builder
    {
        return FiscalDocumentDiscrepancy::withoutGlobalScopes()->where('company_id', $companyId);
    }
}
