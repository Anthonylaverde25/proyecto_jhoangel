<?php

declare(strict_types=1);

namespace App\Application\Mappers;

use App\Core\Entities\VeterinaryDiagnosisEntity;
use App\Core\Enums\DiagnosisStatus;
use App\Models\VeterinaryDiagnosis;
use DateTimeImmutable;

final class VeterinaryDiagnosisMapper
{
    public static function toDomain(VeterinaryDiagnosis $model): VeterinaryDiagnosisEntity
    {
        $pathogen = $model->relationLoaded('pathogen') ? $model->pathogen : null;
        // ADR-3: `veterinarian` is now the catalogue professional; `diagnosedByUser` is the operator.
        $vet = $model->relationLoaded('veterinarian') ? $model->veterinarian : null;
        $operator = $model->relationLoaded('diagnosedByUser') ? $model->diagnosedByUser : null;
        $protocol = $model->relationLoaded('diagnosticProtocol') ? $model->diagnosticProtocol : null;

        return new VeterinaryDiagnosisEntity(
            id: (int) $model->id,
            companyId: (int) $model->company_id,
            caravanId: (int) $model->caravan_id,
            pathogenId: (int) $model->pathogen_id,
            veterinarianId: $model->veterinarian_id !== null ? (int) $model->veterinarian_id : null,
            diagnosisDate: new DateTimeImmutable((string) $model->diagnosis_date),
            status: DiagnosisStatus::from($model->status),
            resolutionDate: $model->resolution_date ? new DateTimeImmutable((string) $model->resolution_date) : null,
            treatmentNotes: $model->treatment_notes,
            sourceContext: (string) ($model->source_context ?? 'PRE_SERVICE'),
            pathogenCode: $pathogen?->code,
            pathogenName: $pathogen?->name,
            pathogenIsDisqualifying: $pathogen !== null ? (bool) $pathogen->is_disqualifying : null,
            veterinarianName: $vet?->name,
            diagnosedByUserId: $model->diagnosed_by_user_id !== null ? (int) $model->diagnosed_by_user_id : null,
            diagnosticProtocolId: $model->diagnostic_protocol_id !== null ? (int) $model->diagnostic_protocol_id : null,
            bullLabSampleId: $model->bull_lab_sample_id !== null ? (int) $model->bull_lab_sample_id : null,
            diagnosedByUserName: $operator?->name,
            protocolNumber: $protocol?->protocol_number
        );
    }
}
