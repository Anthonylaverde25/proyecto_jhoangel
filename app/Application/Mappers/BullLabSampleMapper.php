<?php

declare(strict_types=1);

namespace App\Application\Mappers;

use App\Core\Entities\BullLabSampleEntity;
use App\Core\Enums\LabSampleStatus;
use App\Core\Enums\SampleType;
use App\Models\BullLabSample;
use DateTimeImmutable;

final class BullLabSampleMapper
{
    public static function toDomain(BullLabSample $model): BullLabSampleEntity
    {
        $pathogen = $model->relationLoaded('pathogen') ? $model->pathogen : null;
        $caravan = $model->relationLoaded('caravan') ? $model->caravan : null;

        return new BullLabSampleEntity(
            id: (int) $model->id,
            companyId: (int) $model->company_id,
            caravanId: (int) $model->caravan_id,
            sampleType: SampleType::from((string) $model->sample_type),
            sampleRound: (int) $model->sample_round,
            sampleDate: new DateTimeImmutable((string) $model->sample_date),
            status: LabSampleStatus::from((string) $model->status),
            diagnosticProtocolId: $model->diagnostic_protocol_id !== null ? (int) $model->diagnostic_protocol_id : null,
            veterinarianId: $model->veterinarian_id !== null ? (int) $model->veterinarian_id : null,
            pathogenId: $model->pathogen_id !== null ? (int) $model->pathogen_id : null,
            tubeNumber: $model->tube_number,
            resultDate: $model->result_date ? new DateTimeImmutable((string) $model->result_date) : null,
            notes: $model->notes,
            pathogenCode: $pathogen?->code,
            caravanNumber: $caravan?->identification !== null ? (string) $caravan->identification : null
        );
    }
}
