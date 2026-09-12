<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Mappers\SampleShipmentMapper;
use App\Core\Entities\SampleShipmentEntity;
use App\Core\Enums\DiagnosticProtocolType;
use App\Core\Enums\ProtocolStatus;
use App\Core\Interfaces\ISampleShipmentRepository;
use App\Models\BullLabSample;
use App\Models\SampleShipment;
use Illuminate\Support\Facades\DB;

class EloquentSampleShipmentRepository implements ISampleShipmentRepository
{
    /**
     * @return array<SampleShipmentEntity>
     */
    public function findAll(int $companyId, ?int $veterinarianId = null): array
    {
        $query = SampleShipment::query()
            ->with(['samples.caravan'])
            ->where('company_id', $companyId);

        if ($veterinarianId !== null) {
            $query->where('declared_by_veterinarian_id', $veterinarianId);
        }

        return $query->orderByDesc('shipped_on')
            ->orderByDesc('id')
            ->get()
            ->map(static fn (SampleShipment $m): SampleShipmentEntity => SampleShipmentMapper::toDomain($m))
            ->all();
    }

    public function findById(int $id, int $companyId): ?SampleShipmentEntity
    {
        $model = SampleShipment::query()
            ->with(['samples.caravan'])
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->first();

        return $model ? SampleShipmentMapper::toDomain($model) : null;
    }

    /**
     * ADR-30 / ADR-36: everything the professional still holds, across every act at once. You
     * pack a box, not an act, so the screen that builds one cannot be scoped to a single
     * document.
     *
     * The act must be signed: an unsigned act has no legally identified tubes to hand over.
     *
     * @return array<array<string, mixed>>
     */
    public function findPendingTubes(int $veterinarianId, int $companyId): array
    {
        return BullLabSample::query()
            ->select([
                'bull_lab_samples.id',
                'bull_lab_samples.caravan_id',
                'bull_lab_samples.extraction_act_id',
                'bull_lab_samples.sample_type',
                'bull_lab_samples.tube_number',
                'bull_lab_samples.extracted_on',
                'caravans.identification as caravan_number',
                'diagnostic_protocols.protocol_number as act_number',
                'extraction_act_details.destination_plan',
                'extraction_act_details.destination_institution',
            ])
            ->join('diagnostic_protocols', 'diagnostic_protocols.id', '=', 'bull_lab_samples.extraction_act_id')
            ->leftJoin('extraction_act_details', 'extraction_act_details.protocol_id', '=', 'diagnostic_protocols.id')
            ->leftJoin('caravans', 'caravans.id', '=', 'bull_lab_samples.caravan_id')
            ->where('bull_lab_samples.company_id', $companyId)
            ->whereNull('bull_lab_samples.sample_shipment_id')
            ->where('bull_lab_samples.status', 'PENDING_RESULTS')
            ->where('diagnostic_protocols.veterinarian_id', $veterinarianId)
            ->where('diagnostic_protocols.protocol_type', DiagnosticProtocolType::EXTRACTION_ACT->value)
            ->where('diagnostic_protocols.status', ProtocolStatus::CONFIRMED->value)
            ->orderBy('diagnostic_protocols.sample_date')
            ->orderBy('bull_lab_samples.id')
            ->get()
            ->map(static fn ($row): array => [
                'id' => (int) $row->id,
                'caravan_id' => (int) $row->caravan_id,
                'caravan_number' => $row->caravan_number,
                'extraction_act_id' => (int) $row->extraction_act_id,
                'act_number' => $row->act_number,
                'sample_type' => (string) $row->sample_type,
                'tube_number' => $row->tube_number,
                'extracted_on' => $row->extracted_on,
                'destination_plan' => $row->destination_plan,
                // ADR-39 rev.: el destino que el acta ya declaró, para que el modal de despacho
                // lo vuelva a ofrecer solo. Decodificado a mano: la fila viene de un select
                // crudo y no pasa por el cast del modelo.
                'destination_institution' => $row->destination_institution
                    ? json_decode((string) $row->destination_institution, true)
                    : null,
            ])
            ->all();
    }

    /**
     * §3.9: no catalogue, and no read model either — the suggestions are whatever has already
     * been written, deduplicated by CUIT when there is one and by name when there is not.
     *
     * @return array<array<string, mixed>>
     */
    public function findInstitutionSuggestions(int $companyId, string $search = ''): array
    {
        $rows = SampleShipment::query()
            ->where('company_id', $companyId)
            ->whereNull('voided_at')
            ->orderByDesc('shipped_on')
            ->limit(200)
            ->pluck('institution');

        $seen = [];

        foreach ($rows as $institution) {
            $meta = (array) $institution;
            $name = trim((string) ($meta['nombre'] ?? ''));

            if ($name === '') {
                continue;
            }

            if ($search !== '' && !str_contains(mb_strtolower($name), mb_strtolower($search))) {
                continue;
            }

            // The CUIT is the identity when present; the name is the fallback (ADR-29).
            $key = $meta['cuit'] ?? mb_strtolower($name);

            if (!isset($seen[$key])) {
                $seen[$key] = $meta;
            }
        }

        return array_values($seen);
    }
}
