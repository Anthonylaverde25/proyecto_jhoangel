<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Mappers\BullHealthEvaluationMapper;
use App\Application\Mappers\VeterinaryDiagnosisMapper;
use App\Core\Entities\BullHealthEvaluationEntity;
use App\Core\Enums\LabSampleStatus;
use App\Core\Enums\ReproductiveAptitudeStatus;
use App\Core\Interfaces\IBullHealthEvaluationRepository;
use App\Core\ValueObjects\SampleResult;
use App\Core\ValueObjects\VenerealSamplingStatus;
use App\Models\BullHealthEvaluation;
use App\Models\BullLabSample;
use App\Models\Caravan;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

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

    /**
     * ADR-4: single bull variant. Delegates to the bulk query so both paths apply exactly the
     * same evidence rules — duplicating the WHERE clause here is how the two drift apart.
     */
    public function findVenerealSampling(int $caravanId, int $companyId): VenerealSamplingStatus
    {
        $byCaravan = $this->findVenerealSamplingForCaravans([$caravanId], $companyId);

        return $byCaravan[$caravanId] ?? VenerealSamplingStatus::empty();
    }

    /**
     * Accumulated venereal sampling per bull, as the aptitude engine needs it.
     *
     * ADR-17: only signed evidence CLEARS a bull, and the rule is deliberately asymmetric.
     * A symmetric filter is fail-open: dropping an unsigned PENDING_RESULTS tube does not make
     * the animal look unproven, it makes him look finished, and he walks into the entore. So a
     * determination that would grant clearance needs a signed document behind it, while pending
     * and positive determinations always weigh against him regardless of paperwork.
     *
     * @param list<int> $caravanIds
     * @return array<int, VenerealSamplingStatus>
     */
    public function findVenerealSamplingForCaravans(array $caravanIds, int $companyId): array
    {
        if ($caravanIds === []) {
            return [];
        }

        $requireSignedEvidence = (bool) config('livestock.venereal.require_signed_evidence', true);

        $rows = BullLabSample::query()
            ->select([
                'bull_lab_samples.caravan_id',
                'bull_lab_samples.sample_round',
                // ADR-26: ADR-4 compares negative rounds by date and one act may span two chute
                // days, so the comparison reads the tube's own date, not the act's opening one.
                DB::raw('COALESCE(bull_lab_samples.extracted_on, bull_lab_samples.sample_date) as effective_sample_date'),
                'bull_lab_samples.status',
                'pathogens.code as pathogen_code',
            ])
            ->join('pathogens', 'pathogens.id', '=', 'bull_lab_samples.pathogen_id')
            // The tube may be backed by the act that drew it, by the report that resolved it,
            // or historically by neither.
            ->leftJoin('diagnostic_protocols as reports', 'reports.id', '=', 'bull_lab_samples.diagnostic_protocol_id')
            ->leftJoin('diagnostic_protocols as acts', 'acts.id', '=', 'bull_lab_samples.extraction_act_id')
            ->where('bull_lab_samples.company_id', $companyId)
            ->whereIn('bull_lab_samples.caravan_id', $caravanIds)
            // A VOIDED document is an explicit retraction: nothing hanging off it counts any
            // more, positives included. That is a different thing from merely unsigned.
            ->where(function ($notVoided) {
                $notVoided->whereNull('bull_lab_samples.extraction_act_id')
                    ->orWhere('acts.status', '!=', 'VOIDED');
            })
            ->where(function ($notVoided) {
                $notVoided->whereNull('bull_lab_samples.diagnostic_protocol_id')
                    ->orWhere('reports.status', '!=', 'VOIDED');
            })
            ->where(function ($query) use ($requireSignedEvidence) {
                // Anything that is not a clearance always counts.
                $query->where('bull_lab_samples.status', '!=', LabSampleStatus::NEGATIVE_CLEARED->value)
                    ->orWhere(function ($cleared) use ($requireSignedEvidence) {
                        $cleared->where('bull_lab_samples.status', LabSampleStatus::NEGATIVE_CLEARED->value)
                            ->where(function ($backed) use ($requireSignedEvidence) {
                                // Born under an act: that act must be signed.
                                $backed->where(function ($inner) {
                                    $inner->whereNotNull('bull_lab_samples.extraction_act_id')
                                        ->where('acts.status', 'CONFIRMED');
                                })
                                // Predates the act model: fall back to the report that resolved it.
                                ->orWhere(function ($inner) {
                                    $inner->whereNull('bull_lab_samples.extraction_act_id')
                                        ->whereNotNull('bull_lab_samples.diagnostic_protocol_id')
                                        ->where('reports.status', 'CONFIRMED');
                                });

                                if (!$requireSignedEvidence) {
                                    $backed->orWhere(function ($inner) {
                                        $inner->whereNull('bull_lab_samples.extraction_act_id')
                                            ->whereNull('bull_lab_samples.diagnostic_protocol_id');
                                    });
                                }
                            });
                    });
            })
            ->get();

        $samplesByCaravan = [];

        foreach ($rows as $row) {
            $caravanId = (int) $row->caravan_id;

            $samplesByCaravan[$caravanId][] = new SampleResult(
                pathogenCode: (string) $row->pathogen_code,
                round: (int) $row->sample_round,
                sampleDate: new DateTimeImmutable((string) $row->effective_sample_date),
                status: LabSampleStatus::from((string) $row->status)
            );
        }

        $result = [];

        foreach ($caravanIds as $caravanId) {
            $result[$caravanId] = new VenerealSamplingStatus($samplesByCaravan[$caravanId] ?? []);
        }

        return $result;
    }
}
