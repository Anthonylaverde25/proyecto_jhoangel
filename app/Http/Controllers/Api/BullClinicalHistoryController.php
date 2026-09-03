<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BullClinicalHistoryResource;
use App\Models\Caravan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BullClinicalHistoryController extends Controller
{
    /**
     * Retrieve the comprehensive clinical and andrological history of a bull.
     */
    public function __invoke(Request $request, int $caravanId): JsonResponse
    {
        /** @var Caravan $caravan */
        $caravan = Caravan::query()
            ->with([
                'breedRelation',
                'colorRelation',
                'categoryRelation',
                'batch.farm',
                'currentWeight',
                'bullHealthEvaluations' => function ($query) {
                    $query->orderBy('last_evaluation_date', 'desc');
                },
                'bullLabSamples' => function ($query) {
                    $query->with('pathogen')->orderBy('sample_date', 'desc');
                },
                'diagnoses' => function ($query) {
                    $query->with(['pathogen', 'veterinarian'])->orderBy('diagnosis_date', 'desc');
                },
            ])
            ->findOrFail($caravanId);

        // Compute biometrical & scrotal circumference metrics
        $evaluations = $caravan->bullHealthEvaluations;
        $latestEval = $evaluations->first();
        $oldestEval = $evaluations->last();

        $latestCe = $latestEval?->scrotal_circumference_cm !== null ? (float) $latestEval->scrotal_circumference_cm : null;
        $oldestCe = $oldestEval?->scrotal_circumference_cm !== null ? (float) $oldestEval->scrotal_circumference_cm : null;
        $ceDelta = ($latestCe !== null && $oldestCe !== null) ? round($latestCe - $oldestCe, 1) : 0.0;

        // Active clinical diagnoses
        $activeDiagnoses = $caravan->diagnoses->filter(fn ($d) => $d->status === 'ACTIVE');
        $hasDisqualifyingDiagnosis = $activeDiagnoses->contains(fn ($d) => (bool) $d->pathogen?->is_disqualifying);

        // Laboratory pending status
        $hasPendingSamples = $caravan->bullLabSamples->contains(fn ($s) => $s->status === 'PENDING_RESULTS');
        $hasPositiveSamples = $caravan->bullLabSamples->contains(fn ($s) => $s->status === 'POSITIVE_DETECTED');

        // Computed reproductive status according to Carrillo (1988)
        if ($hasDisqualifyingDiagnosis || $hasPositiveSamples || ($latestCe !== null && $latestCe < 28.0)) {
            $computedStatus = 'UNFIT';
        } elseif ($activeDiagnoses->isNotEmpty()) {
            $computedStatus = 'IN_TREATMENT';
        } elseif ($hasPendingSamples || $latestEval === null) {
            $computedStatus = 'PENDING_EVALUATION';
        } else {
            $computedStatus = 'APT';
        }

        $payload = [
            'caravan' => $caravan,
            'computed_status' => $computedStatus,
            'metrics' => [
                'latest_ce_cm' => $latestCe,
                'oldest_ce_cm' => $oldestCe,
                'ce_delta_cm' => $ceDelta,
                'is_ce_compliant' => $latestCe !== null && $latestCe >= 28.0,
                'evaluations_count' => $evaluations->count(),
                'lab_samples_count' => $caravan->bullLabSamples->count(),
                'active_diagnoses_count' => $activeDiagnoses->count(),
                'total_diagnoses_count' => $caravan->diagnoses->count(),
            ],
            'evaluations' => $evaluations,
            'lab_samples' => $caravan->bullLabSamples,
            'diagnoses' => $caravan->diagnoses,
        ];

        return response()->json([
            'data' => (new BullClinicalHistoryResource($payload))->resolve(),
        ]);
    }
}
