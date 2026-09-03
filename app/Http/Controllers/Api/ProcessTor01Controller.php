<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\Interfaces\ICompanyContext;
use App\Http\Controllers\Controller;
use App\Models\BullHealthEvaluation;
use App\Models\BullLabSample;
use App\Models\Caravan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ProcessTor01Controller extends Controller
{
    public function __construct(
        private readonly ICompanyContext $companyContext
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $companyId = $this->companyContext->getCompanyId() ?? 1;

        $validated = $request->validate([
            'evaluation_date' => 'nullable|date',
            'veterinarian_name' => 'nullable|string',
            'veterinarian_license' => 'nullable|string',
            'sample_round' => 'nullable|integer|min:1|max:5',
            'rows' => 'required|array|min:1',
            'rows.*.caravana' => 'required|string',
            'rows.*.ce_cm' => 'nullable|numeric|min:15|max:60',
            'rows.*.bcs' => 'nullable|numeric|min:1|max:5',
            'rows.*.libido' => 'nullable|string',
            'rows.*.aplomos' => 'nullable|string',
            'rows.*.scrape_collected' => 'nullable',
            'rows.*.scrape_tube' => 'nullable|string',
            'rows.*.serology_collected' => 'nullable',
            'rows.*.serology_tube' => 'nullable|string',
            'rows.*.physical_verdict' => 'nullable|string',
            'rows.*.observations' => 'nullable|string',
        ]);

        try {
            $createdCount = DB::transaction(function () use ($validated, $companyId) {
                $evalDate = $validated['evaluation_date'] ?? date('Y-m-d');
                $sampleRound = (int) ($validated['sample_round'] ?? 1);
                $savedBulls = 0;

                foreach ($validated['rows'] as $row) {
                    $caravanTag = trim((string) $row['caravana']);
                    if ($caravanTag === '') continue;

                    $caravan = Caravan::where('company_id', $companyId)
                        ->where('identification', $caravanTag)
                        ->first();

                    if (!$caravan) continue;

                    $ce = isset($row['ce_cm']) && $row['ce_cm'] !== null ? (float) $row['ce_cm'] : null;
                    $bcs = isset($row['bcs']) && $row['bcs'] !== null ? (float) $row['bcs'] : null;
                    $libido = !empty($row['libido']) ? strtoupper(trim((string)$row['libido'])) : 'MEDIA';
                    $aplomos = $row['aplomos'] ?? null;
                    $obs = $row['observations'] ?? null;

                    // Compute initial verdict
                    $isScrape = !empty($row['scrape_collected']) && !in_array(strtolower((string)$row['scrape_collected']), ['no', 'false', '0']);
                    $isSero = !empty($row['serology_collected']) && !in_array(strtolower((string)$row['serology_collected']), ['no', 'false', '0']);

                    $status = 'PENDING_EVALUATION';
                    if ($ce !== null && $ce < 28.0) {
                        $status = 'UNFIT';
                    } elseif (!$isScrape && !$isSero && $ce !== null && $ce >= 28.0) {
                        $status = 'APT';
                    }

                    $eval = BullHealthEvaluation::create([
                        'company_id' => $companyId,
                        'caravan_id' => $caravan->id,
                        'last_evaluation_date' => $evalDate,
                        'aplomo_notes' => $aplomos,
                        'scrotal_circumference_cm' => $ce,
                        'body_condition_score' => $bcs,
                        'libido' => in_array($libido, ['BAJA', 'MEDIA', 'ALTA', 'MUY_ALTA']) ? $libido : 'MEDIA',
                        'status' => $status,
                        'observations' => $obs,
                    ]);

                    // Register Preputial Scrape sample if collected
                    if ($isScrape) {
                        BullLabSample::create([
                            'company_id' => $companyId,
                            'caravan_id' => $caravan->id,
                            'evaluation_id' => $eval->id,
                            'sample_type' => 'PREPUCE_SCRAPE',
                            'sample_round' => $sampleRound,
                            'sample_date' => $evalDate,
                            'tube_number' => $row['scrape_tube'] ?? null,
                            'status' => 'PENDING_RESULTS',
                        ]);
                    }

                    // Register Blood Serology sample if collected
                    if ($isSero) {
                        BullLabSample::create([
                            'company_id' => $companyId,
                            'caravan_id' => $caravan->id,
                            'evaluation_id' => $eval->id,
                            'sample_type' => 'BLOOD_SEROLOGY',
                            'sample_round' => 1,
                            'sample_date' => $evalDate,
                            'tube_number' => $row['serology_tube'] ?? null,
                            'status' => 'PENDING_RESULTS',
                        ]);
                    }

                    $savedBulls++;
                }

                return $savedBulls;
            });

            return response()->json([
                'status' => 'success',
                'message' => "Planilla TOR-01 procesada exitosamente ({$createdCount} toros evaluados).",
                'evaluated_count' => $createdCount,
            ], 201);
        } catch (Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al procesar planilla TOR-01: ' . $e->getMessage(),
            ], 500);
        }
    }
}
