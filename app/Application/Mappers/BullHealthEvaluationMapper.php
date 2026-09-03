<?php

declare(strict_types=1);

namespace App\Application\Mappers;

use App\Core\Entities\BullHealthEvaluationEntity;
use App\Core\Enums\ReproductiveAptitudeStatus;
use App\Models\BullHealthEvaluation;
use DateTimeImmutable;

final class BullHealthEvaluationMapper
{
    public static function toDomain(BullHealthEvaluation $model): BullHealthEvaluationEntity
    {
        $caravan = $model->relationLoaded('caravan') ? $model->caravan : null;
        $activeDiagnoses = [];

        if ($caravan && $caravan->relationLoaded('diagnoses')) {
            foreach ($caravan->diagnoses as $diagnosis) {
                if (in_array($diagnosis->status, ['CONFIRMED_POSITIVE', 'IN_TREATMENT'], true)) {
                    $activeDiagnoses[] = VeterinaryDiagnosisMapper::toDomain($diagnosis);
                }
            }
        }

        $labSamples = [];
        if ($model->relationLoaded('labSamples')) {
            $labSamples = $model->labSamples->all();
        } elseif ($caravan && $caravan->relationLoaded('bullLabSamples')) {
            $labSamples = $caravan->bullLabSamples->all();
        }

        return new BullHealthEvaluationEntity(
            id: (int) $model->id,
            companyId: (int) $model->company_id,
            caravanId: (int) $model->caravan_id,
            lastEvaluationDate: $model->last_evaluation_date ? new DateTimeImmutable((string) $model->last_evaluation_date) : null,
            aplomoNotes: $model->aplomo_notes,
            scrotalCircumferenceCm: $model->scrotal_circumference_cm !== null ? (float) $model->scrotal_circumference_cm : null,
            bodyConditionScore: $model->body_condition_score !== null ? (float) $model->body_condition_score : null,
            libido: (string) ($model->libido ?? 'MEDIA'),
            status: ReproductiveAptitudeStatus::from($model->status),
            observations: $model->observations,
            caravanNumber: (string) ($caravan?->identification ?? ''),
            activeDiagnoses: $activeDiagnoses,
            labSamples: $labSamples
        );
    }
}
