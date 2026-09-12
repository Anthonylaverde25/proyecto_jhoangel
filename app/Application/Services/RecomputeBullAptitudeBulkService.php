<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Application\Mappers\VeterinaryDiagnosisMapper;
use App\Core\Enums\DiagnosisStatus;
use App\Core\Interfaces\IBullHealthEvaluationRepository;
use App\Core\Services\BullHealthEvaluationEngine;
use App\Models\BullHealthEvaluation;
use App\Models\VeterinaryDiagnosis;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ADR-10: recomputes reproductive aptitude for a whole troop without the N+1 the naive
 * implementation incurs (one `findByCaravanId` per bull, each with heavy eager loads).
 *
 * Shape of the work: bulk load -> compute in memory -> one write pass.
 */
final class RecomputeBullAptitudeBulkService
{
    public function __construct(
        private readonly BullHealthEvaluationEngine $engine,
        private readonly IBullHealthEvaluationRepository $bullHealthRepository
    ) {
    }

    /**
     * @param list<int> $caravanIds
     * @return array<int, string> New aptitude status keyed by caravan id.
     */
    public function __invoke(array $caravanIds, int $companyId, ?string $evaluationDate = null): array
    {
        $caravanIds = array_values(array_unique(array_map('intval', $caravanIds)));

        if ($caravanIds === []) {
            return [];
        }

        $evaluations = $this->loadLatestEvaluations($caravanIds, $companyId);
        $activeDiagnoses = $this->loadActiveDiagnoses($caravanIds, $companyId);
        $sampling = $this->bullHealthRepository->findVenerealSamplingForCaravans($caravanIds, $companyId);

        $statuses = [];
        $updates = [];
        $inserts = [];
        $fallbackDate = $evaluationDate ?? Carbon::now()->toDateString();

        foreach ($caravanIds as $caravanId) {
            $evaluation = $evaluations[$caravanId] ?? null;

            $status = $this->engine->computeAptitude(
                $evaluation?->scrotal_circumference_cm !== null ? (float) $evaluation->scrotal_circumference_cm : null,
                $evaluation?->body_condition_score !== null ? (float) $evaluation->body_condition_score : null,
                $evaluation?->aplomo_notes,
                $activeDiagnoses[$caravanId] ?? [],
                $sampling[$caravanId] ?? null
            );

            $statuses[$caravanId] = $status->value;

            if ($evaluation === null) {
                // The bull has laboratory history but was never examined at the chute. A row is
                // still created so the sanitary lock has something deterministic to read.
                $inserts[] = [
                    'company_id' => $companyId,
                    'caravan_id' => $caravanId,
                    'last_evaluation_date' => $fallbackDate,
                    'libido' => 'MEDIA',
                    'status' => $status->value,
                    'observations' => 'Aptitud derivada de resultados de laboratorio, sin examen físico registrado.',
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ];

                continue;
            }

            if ((string) $evaluation->status !== $status->value) {
                $updates[$status->value][] = (int) $evaluation->id;
            }
        }

        // One UPDATE per resulting status instead of one per bull.
        foreach ($updates as $statusValue => $ids) {
            BullHealthEvaluation::query()
                ->whereIn('id', $ids)
                ->update(['status' => $statusValue, 'updated_at' => Carbon::now()]);
        }

        if ($inserts !== []) {
            DB::table('bull_health_evaluations')->insert($inserts);
        }

        return $statuses;
    }

    /**
     * @param list<int> $caravanIds
     * @return array<int, BullHealthEvaluation>
     */
    private function loadLatestEvaluations(array $caravanIds, int $companyId): array
    {
        $rows = BullHealthEvaluation::query()
            ->where('company_id', $companyId)
            ->whereIn('caravan_id', $caravanIds)
            ->orderBy('caravan_id')
            ->orderBy('last_evaluation_date')
            ->orderBy('id')
            ->get();

        $latest = [];

        // Ordered ascending, so the last row seen per caravan is the most recent one.
        foreach ($rows as $row) {
            $latest[(int) $row->caravan_id] = $row;
        }

        return $latest;
    }

    /**
     * @param list<int> $caravanIds
     * @return array<int, list<\App\Core\Entities\VeterinaryDiagnosisEntity>>
     */
    private function loadActiveDiagnoses(array $caravanIds, int $companyId): array
    {
        $rows = VeterinaryDiagnosis::query()
            ->with('pathogen')
            ->where('company_id', $companyId)
            ->whereIn('caravan_id', $caravanIds)
            ->whereIn('status', [
                DiagnosisStatus::CONFIRMED_POSITIVE->value,
                DiagnosisStatus::IN_TREATMENT->value,
            ])
            ->get();

        $byCaravan = [];

        foreach ($rows as $row) {
            $byCaravan[(int) $row->caravan_id][] = VeterinaryDiagnosisMapper::toDomain($row);
        }

        return $byCaravan;
    }
}
