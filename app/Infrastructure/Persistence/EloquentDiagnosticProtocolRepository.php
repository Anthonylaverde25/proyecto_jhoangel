<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Mappers\DiagnosticProtocolMapper;
use App\Core\Entities\DiagnosticProtocolEntity;
use App\Core\Enums\DiagnosticProtocolType;
use App\Core\Enums\ProtocolStatus;
use App\Core\Interfaces\IDiagnosticProtocolRepository;
use App\Models\BullLabSample;
use App\Models\Caravan;
use App\Models\DiagnosticProtocol;
use Illuminate\Support\Carbon;

class EloquentDiagnosticProtocolRepository implements IDiagnosticProtocolRepository
{
    /**
     * @param array<string, mixed> $filters
     * @return array<DiagnosticProtocolEntity>
     */
    public function findAll(int $companyId, array $filters = []): array
    {
        $query = DiagnosticProtocol::query()
            // ADR-42: the per-type detail travels with every read, so no caller pays an N+1 to
            // find out which institution a protocol names.
            ->with(['veterinarian', 'attachments', 'extractionActDetail', 'labReportDetail'])
            ->withCount('labSamples')
            ->where('company_id', $companyId);

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['source_channel'])) {
            $query->where('source_channel', $filters['source_channel']);
        }

        if (!empty($filters['verification_status'])) {
            $query->where('verification_status', $filters['verification_status']);
        }

        if (!empty($filters['veterinarian_id'])) {
            $query->where('veterinarian_id', (int) $filters['veterinarian_id']);
        }

        if (!empty($filters['protocol_type'])) {
            $query->where('protocol_type', $filters['protocol_type']);
        }

        if (!empty($filters['from_date'])) {
            $query->whereDate('result_date', '>=', $filters['from_date']);
        }

        if (!empty($filters['to_date'])) {
            $query->whereDate('result_date', '<=', $filters['to_date']);
        }

        if (!empty($filters['search'])) {
            $query->where('protocol_number', 'like', '%' . $filters['search'] . '%');
        }

        // An extraction act has no result_date, so ordering by it alone would bury every act
        // below the reports. COALESCE keeps both document types on one timeline.
        return $query->orderByRaw('COALESCE(result_date, sample_date) DESC')
            ->orderByDesc('id')
            ->get()
            ->map(static fn (DiagnosticProtocol $model): DiagnosticProtocolEntity => DiagnosticProtocolMapper::toDomain($model))
            ->all();
    }

    public function findById(int $id, int $companyId): ?DiagnosticProtocolEntity
    {
        $model = DiagnosticProtocol::query()
            ->with([
                'veterinarian',
                'attachments',
                'labSamples.pathogen',
                'labSamples.caravan',
                'drawnSamples.pathogen',
                'drawnSamples.caravan',
                'extractionActDetail',
                'labReportDetail',
            ])
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->first();

        return $model ? DiagnosticProtocolMapper::toDomain($model) : null;
    }

    public function findByProtocolNumber(string $protocolNumber, int $companyId): ?DiagnosticProtocolEntity
    {
        $model = DiagnosticProtocol::query()
            ->with(['veterinarian', 'attachments', 'extractionActDetail', 'labReportDetail'])
            ->where('protocol_number', $protocolNumber)
            ->where('company_id', $companyId)
            ->first();

        return $model ? DiagnosticProtocolMapper::toDomain($model) : null;
    }

    public function existsByProtocolNumber(string $protocolNumber, int $companyId): bool
    {
        return DiagnosticProtocol::query()
            ->where('protocol_number', $protocolNumber)
            ->where('company_id', $companyId)
            ->exists();
    }

    /**
     * @return array<DiagnosticProtocolEntity>
     */
    public function findActsPendingSignature(int $veterinarianId, int $companyId): array
    {
        return $this->actsQuery($companyId, $veterinarianId)
            ->where('status', ProtocolStatus::DRAFT->value)
            ->whereNull('signed_at')
            ->orderByDesc('sample_date')
            ->orderByDesc('id')
            ->get()
            ->map(static fn (DiagnosticProtocol $model): DiagnosticProtocolEntity => DiagnosticProtocolMapper::toDomain($model))
            ->all();
    }

    /**
     * @return array<DiagnosticProtocolEntity>
     */
    public function findActsPendingLabReport(int $veterinarianId, int $companyId): array
    {
        return $this->actsQuery($companyId, $veterinarianId)
            ->where('status', ProtocolStatus::CONFIRMED->value)
            ->whereNotNull('signed_at')
            ->whereHas('drawnSamples', static function ($query): void {
                $query->where('status', 'PENDING_RESULTS');
            })
            ->orderByDesc('sample_date')
            ->orderByDesc('id')
            ->get()
            ->map(static fn (DiagnosticProtocol $model): DiagnosticProtocolEntity => DiagnosticProtocolMapper::toDomain($model))
            ->all();
    }

    /**
     * ADR-14: the batches the professional actually worked on, walked back from the tubes their
     * own acts created. This is what makes the act — not a manual batch assignment — the unit of
     * responsibility.
     *
     * @return list<int>
     */
    public function findBatchIdsWithActsFor(int $veterinarianId, int $companyId): array
    {
        $caravanIds = BullLabSample::query()
            ->join('diagnostic_protocols', 'diagnostic_protocols.id', '=', 'bull_lab_samples.extraction_act_id')
            ->where('bull_lab_samples.company_id', $companyId)
            ->where('diagnostic_protocols.veterinarian_id', $veterinarianId)
            ->where('diagnostic_protocols.status', '!=', ProtocolStatus::VOIDED->value)
            ->pluck('bull_lab_samples.caravan_id')
            ->unique()
            ->all();

        if ($caravanIds === []) {
            return [];
        }

        return Caravan::query()
            ->where('company_id', $companyId)
            ->whereIn('id', $caravanIds)
            ->whereNotNull('batch_id')
            ->pluck('batch_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * §11.6 reformulated: dispatched long ago, still silent. The threshold is a policy that
     * lives in configuration — a number nobody chose has no business raising alarms on its own.
     *
     * @return array<DiagnosticProtocolEntity>
     */
    public function findActsShippedWithoutReport(int $companyId, int $overdueDays): array
    {
        $cutoff = Carbon::now()->subDays($overdueDays)->toDateString();

        return DiagnosticProtocol::query()
            ->with(['veterinarian', 'drawnSamples.caravan', 'extractionActDetail'])
            ->where('company_id', $companyId)
            ->where('protocol_type', DiagnosticProtocolType::EXTRACTION_ACT->value)
            ->where('status', '!=', ProtocolStatus::VOIDED->value)
            ->whereHas('drawnSamples', static function ($query) use ($cutoff): void {
                $query->whereNotNull('sample_shipment_id')
                    ->where('status', 'PENDING_RESULTS')
                    ->whereHas('shipment', static function ($shipment) use ($cutoff): void {
                        $shipment->whereNull('voided_at')->whereDate('shipped_on', '<=', $cutoff);
                    });
            })
            ->orderByDesc('sample_date')
            ->get()
            ->map(static fn (DiagnosticProtocol $model): DiagnosticProtocolEntity => DiagnosticProtocolMapper::toDomain($model))
            ->all();
    }

    /**
     * The MOST RECENT report on an act, which is not necessarily the only one.
     *
     * `labReports()` is a HasMany on purpose: fractioned work sends the tubes of one chute to two
     * laboratories and gets two reports back (ADR-36). This used to be a bare `first()` with no
     * ordering, so which of the two came back was whatever the engine felt like returning. It is
     * ordered now, and `findLabReportsForAct` exists for callers that need all of them.
     */
    public function findLabReportForAct(int $actId, int $companyId): ?DiagnosticProtocolEntity
    {
        $model = DiagnosticProtocol::query()
            ->with([
                'veterinarian', 'labSamples.pathogen', 'labSamples.caravan',
                'extractionActDetail', 'labReportDetail',
            ])
            ->where('company_id', $companyId)
            ->where('parent_protocol_id', $actId)
            ->where('protocol_type', DiagnosticProtocolType::LAB_REPORT->value)
            ->where('status', '!=', ProtocolStatus::VOIDED->value)
            ->orderByDesc('result_date')
            ->orderByDesc('id')
            ->first();

        return $model ? DiagnosticProtocolMapper::toDomain($model) : null;
    }

    /**
     * Every live report on an act, newest first. One chute can be resolved by several laboratories.
     *
     * @return array<DiagnosticProtocolEntity>
     */
    public function findLabReportsForAct(int $actId, int $companyId): array
    {
        return DiagnosticProtocol::query()
            ->with([
                'veterinarian', 'labSamples.pathogen', 'labSamples.caravan',
                'extractionActDetail', 'labReportDetail',
            ])
            ->where('company_id', $companyId)
            ->where('parent_protocol_id', $actId)
            ->where('protocol_type', DiagnosticProtocolType::LAB_REPORT->value)
            ->where('status', '!=', ProtocolStatus::VOIDED->value)
            ->orderByDesc('result_date')
            ->orderByDesc('id')
            ->get()
            ->map(static fn (DiagnosticProtocol $model): DiagnosticProtocolEntity => DiagnosticProtocolMapper::toDomain($model))
            ->all();
    }

    /**
     * Shared shape of every "my acts" query: one professional, extraction acts only, with the
     * tubes they created eager loaded so the inbox can show pending counts without an N+1.
     */
    private function actsQuery(int $companyId, int $veterinarianId): \Illuminate\Database\Eloquent\Builder
    {
        return DiagnosticProtocol::query()
            ->with([
                'veterinarian',
                'drawnSamples.pathogen',
                'drawnSamples.caravan',
                'extractionActDetail',
            ])
            ->where('company_id', $companyId)
            ->where('veterinarian_id', $veterinarianId)
            ->where('protocol_type', DiagnosticProtocolType::EXTRACTION_ACT->value);
    }
}
