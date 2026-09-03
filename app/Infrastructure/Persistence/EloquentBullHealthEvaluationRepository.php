<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Mappers\BullHealthEvaluationMapper;
use App\Application\Mappers\VeterinaryDiagnosisMapper;
use App\Core\Entities\BullHealthEvaluationEntity;
use App\Core\Enums\ReproductiveAptitudeStatus;
use App\Core\Interfaces\IBullHealthEvaluationRepository;
use App\Models\BullHealthEvaluation;
use App\Models\Caravan;
use DateTimeImmutable;

class EloquentBullHealthEvaluationRepository implements IBullHealthEvaluationRepository
{
    public function save(BullHealthEvaluationEntity $evaluation): BullHealthEvaluationEntity
    {
        $attributes = [
            'company_id' => $evaluation->getCompanyId(),
            'caravan_id' => $evaluation->getCaravanId(),
            'last_evaluation_date' => $evaluation->getLastEvaluationDate()?->format('Y-m-d'),
            'aplomo_notes' => $evaluation->getAplomoNotes(),
            'scrotal_circumference_cm' => $evaluation->getScrotalCircumferenceCm(),
            'body_condition_score' => $evaluation->getBodyConditionScore(),
            'libido' => $evaluation->getLibido(),
            'status' => $evaluation->getStatus()->value,
            'observations' => $evaluation->getObservations(),
        ];

        if ($evaluation->getId()) {
            $model = BullHealthEvaluation::findOrFail($evaluation->getId());
            $model->update($attributes);
        } else {
            $model = BullHealthEvaluation::create($attributes);
        }

        $model->load([
            'caravan.diagnoses.pathogen',
            'caravan.diagnoses.veterinarian',
            'caravan.bullLabSamples.pathogen',
            'labSamples.pathogen',
        ]);

        return BullHealthEvaluationMapper::toDomain($model);
    }

    public function findByCaravanId(int $caravanId, int $companyId): ?BullHealthEvaluationEntity
    {
        $model = BullHealthEvaluation::with([
            'caravan.diagnoses.pathogen',
            'caravan.diagnoses.veterinarian',
            'caravan.bullLabSamples.pathogen',
            'labSamples.pathogen',
        ])
            ->where('caravan_id', $caravanId)
            ->where('company_id', $companyId)
            ->latest('last_evaluation_date')
            ->first();

        return $model ? BullHealthEvaluationMapper::toDomain($model) : null;
    }

    /**
     * @return array<BullHealthEvaluationEntity>
     */
    public function findAllBullsWithHealth(int $companyId): array
    {
        // 1. Fetch all male caravans in company with health evaluations, lab samples and active diagnoses
        $bullCaravans = Caravan::with([
            'bullHealthEvaluation',
            'bullLabSamples.pathogen',
            'diagnoses.pathogen',
            'diagnoses.veterinarian',
            'categoryRelation',
        ])
            ->where('company_id', $companyId)
            ->where(function ($query) {
                $query->whereIn('sex', ['M', 'MACHO', 'MALE'])
                    ->orWhereHas('categoryRelation', function ($catQuery) {
                        $catQuery->whereIn('code', ['TORO', 'TORITO']);
                    });
            })
            ->orderBy('identification')
            ->get();

        $result = [];

        foreach ($bullCaravans as $caravan) {
            $activeDiagnoses = [];
            foreach ($caravan->diagnoses as $diag) {
                if (in_array($diag->status, ['CONFIRMED_POSITIVE', 'IN_TREATMENT'], true)) {
                    $activeDiagnoses[] = VeterinaryDiagnosisMapper::toDomain($diag);
                }
            }

            $labSamples = $caravan->bullLabSamples->all();

            if ($caravan->bullHealthEvaluation) {
                $evalModel = $caravan->bullHealthEvaluation;
                $result[] = new BullHealthEvaluationEntity(
                    id: (int) $evalModel->id,
                    companyId: (int) $evalModel->company_id,
                    caravanId: (int) $evalModel->caravan_id,
                    lastEvaluationDate: $evalModel->last_evaluation_date ? new DateTimeImmutable((string) $evalModel->last_evaluation_date) : null,
                    aplomoNotes: $evalModel->aplomo_notes,
                    scrotalCircumferenceCm: $evalModel->scrotal_circumference_cm !== null ? (float) $evalModel->scrotal_circumference_cm : null,
                    bodyConditionScore: $evalModel->body_condition_score !== null ? (float) $evalModel->body_condition_score : null,
                    libido: (string) ($evalModel->libido ?? 'MEDIA'),
                    status: ReproductiveAptitudeStatus::from($evalModel->status),
                    observations: $evalModel->observations,
                    caravanNumber: (string) $caravan->identification,
                    activeDiagnoses: $activeDiagnoses,
                    labSamples: $labSamples
                );
            } else {
                // Bull hasn't been formally evaluated yet in manga
                $result[] = new BullHealthEvaluationEntity(
                    id: null,
                    companyId: (int) $caravan->company_id,
                    caravanId: (int) $caravan->id,
                    lastEvaluationDate: null,
                    aplomoNotes: null,
                    scrotalCircumferenceCm: null,
                    bodyConditionScore: null,
                    libido: 'MEDIA',
                    status: ReproductiveAptitudeStatus::PENDING_EVALUATION,
                    observations: null,
                    caravanNumber: (string) $caravan->identification,
                    activeDiagnoses: $activeDiagnoses,
                    labSamples: $labSamples
                );
            }
        }

        return $result;
    }
}
