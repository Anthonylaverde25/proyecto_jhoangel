<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\Interfaces\ICompanyContext;
use App\Http\Controllers\Controller;
use App\Models\BullHealthEvaluation;
use App\Models\BullLabSample;
use App\Models\Caravan;
use App\Models\VeterinaryDiagnosis;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ProcessLabResultsController extends Controller
{
    public function __construct(
        private readonly ICompanyContext $companyContext
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $companyId = $this->companyContext->getCompanyId() ?? 1;

        $validated = $request->validate([
            'protocol_number' => 'required|string|max:100',
            'result_date' => 'required|date',
            'notes' => 'nullable|string',
            'results' => 'required|array|min:1',
            'results.*.sample_id' => 'required|integer|exists:bull_lab_samples,id',
            'results.*.status' => 'required|string|in:NEGATIVE_CLEARED,POSITIVE_DETECTED',
            'results.*.pathogen_id' => 'nullable|integer|exists:pathogens,id',
        ]);

        try {
            DB::transaction(function () use ($validated, $companyId) {
                $protocolNumber = $validated['protocol_number'];
                $resultDate = $validated['result_date'];
                $notes = $validated['notes'] ?? null;

                $affectedCaravanIds = [];

                foreach ($validated['results'] as $item) {
                    $sample = BullLabSample::where('company_id', $companyId)
                        ->findOrFail($item['sample_id']);

                    $status = $item['status'];
                    $pathogenId = $item['pathogen_id'] ?? null;

                    $sample->update([
                        'status' => $status,
                        'protocol_number' => $protocolNumber,
                        'result_date' => $resultDate,
                        'pathogen_id' => $pathogenId,
                        'notes' => $notes,
                    ]);

                    $affectedCaravanIds[$sample->caravan_id] = true;

                    // If positive, record clinical veterinary diagnosis immediately
                    if ($status === 'POSITIVE_DETECTED' && $pathogenId) {
                        VeterinaryDiagnosis::create([
                            'company_id' => $companyId,
                            'caravan_id' => $sample->caravan_id,
                            'pathogen_id' => $pathogenId,
                            'diagnosis_date' => $resultDate,
                            'status' => 'CONFIRMED_POSITIVE',
                            'treatment_notes' => "Detectado por protocolo de laboratorio {$protocolNumber}",
                            'source_context' => 'PRE_SERVICE',
                        ]);

                        // Bull becomes UNFIT immediately to protect herd
                        $latestEval = BullHealthEvaluation::where('company_id', $companyId)
                            ->where('caravan_id', $sample->caravan_id)
                            ->latest('last_evaluation_date')
                            ->first();

                        $latestEval?->update(['status' => 'UNFIT']);
                    }
                }

                // Check for bulls whose samples are all cleared
                foreach (array_keys($affectedCaravanIds) as $caravanId) {
                    $hasPending = BullLabSample::where('company_id', $companyId)
                        ->where('caravan_id', $caravanId)
                        ->where('status', 'PENDING_RESULTS')
                        ->exists();

                    $hasPositive = BullLabSample::where('company_id', $companyId)
                        ->where('caravan_id', $caravanId)
                        ->where('status', 'POSITIVE_DETECTED')
                        ->exists();

                    $hasActiveDiag = VeterinaryDiagnosis::where('company_id', $companyId)
                        ->where('caravan_id', $caravanId)
                        ->whereIn('status', ['CONFIRMED_POSITIVE', 'IN_TREATMENT'])
                        ->exists();

                    $latestEval = BullHealthEvaluation::where('company_id', $companyId)
                        ->where('caravan_id', $caravanId)
                        ->latest('last_evaluation_date')
                        ->first();

                    if ($latestEval && !$hasPending && !$hasPositive && !$hasActiveDiag) {
                        // Check if physical parameters meet Carrillo criteria
                        $ce = $latestEval->scrotal_circumference_cm;
                        if ($ce !== null && $ce >= 28.0) {
                            $latestEval->update(['status' => 'APT']);
                        }
                    }
                }
            });

            return response()->json([
                'status' => 'success',
                'message' => 'Protocolo analítico procesado exitosamente. Estados actualizados.',
            ], 200);
        } catch (Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al procesar el protocolo analítico: ' . $e->getMessage(),
            ], 500);
        }
    }
}
