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
        $vet = $model->relationLoaded('veterinarian') ? $model->veterinarian : null;

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
            veterinarianName: $vet?->name
        );
    }
}
