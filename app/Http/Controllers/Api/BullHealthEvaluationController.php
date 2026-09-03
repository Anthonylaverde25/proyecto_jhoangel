<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\DTOs\PreService\RegisterBullHealthEvaluationDTO;
use App\Application\DTOs\Veterinary\CreateVeterinaryDiagnosisDTO;
use App\Application\DTOs\Veterinary\ResolveVeterinaryDiagnosisDTO;
use App\Application\UseCases\PreService\ListPathogensUseCase;
use App\Application\UseCases\PreService\ListPreServiceBullsUseCase;
use App\Application\UseCases\PreService\RegisterBullHealthEvaluationUseCase;
use App\Application\UseCases\Veterinary\CreateVeterinaryDiagnosisUseCase;
use App\Application\UseCases\Veterinary\ResolveVeterinaryDiagnosisUseCase;
use App\Http\Controllers\Controller;
use App\Http\Resources\BullHealthEvaluationResource;
use App\Http\Resources\PathogenResource;
use App\Http\Resources\VeterinaryDiagnosisResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BullHealthEvaluationController extends Controller
{
    /**
     * Get all bulls with their physical health and active diagnoses.
     */
    public function getBulls(ListPreServiceBullsUseCase $useCase): AnonymousResourceCollection
    {
        $bulls = $useCase();

        return BullHealthEvaluationResource::collection($bulls);
    }

    /**
     * Get catalog of pathogens.
     */
    public function getPathogens(ListPathogensUseCase $useCase): AnonymousResourceCollection
    {
        $pathogens = $useCase();

        return PathogenResource::collection($pathogens);
    }

    /**
     * Register physical evaluation and optional diagnosis in manga.
     */
    public function registerBullEvaluation(
        Request $request,
        RegisterBullHealthEvaluationUseCase $useCase
    ): JsonResponse {
        $validated = $request->validate([
            'caravan_id' => 'required|integer|exists:caravans,id',
            'last_evaluation_date' => 'nullable|date',
            'aplomo_notes' => 'nullable|string',
            'scrotal_circumference_cm' => 'nullable|numeric|min:15|max:60',
            'body_condition_score' => 'nullable|numeric|min:1|max:5',
            'libido' => 'nullable|string|in:BAJA,MEDIA,ALTA,MUY_ALTA',
            'observations' => 'nullable|string',
            'diagnosis' => 'nullable|array',
            'diagnosis.pathogen_id' => 'nullable|integer|exists:pathogens,id',
            'diagnosis.veterinarian_id' => 'nullable|integer|exists:users,id',
            'diagnosis.diagnosis_date' => 'nullable|date',
            'diagnosis.status' => 'nullable|string|in:CONFIRMED_POSITIVE,IN_TREATMENT,RESOLVED,SUSPECTED',
            'diagnosis.treatment_notes' => 'nullable|string',
        ]);

        $dto = RegisterBullHealthEvaluationDTO::fromArray($validated);
        $result = $useCase($dto);

        return (new BullHealthEvaluationResource($result))
            ->response()
            ->setStatusCode(200);
    }

    /**
     * Record a veterinary diagnosis on any caravan (male or female).
     */
    public function createDiagnosis(
        Request $request,
        int $caravanId,
        CreateVeterinaryDiagnosisUseCase $useCase
    ): JsonResponse {
        $validated = $request->validate([
            'pathogen_id' => 'required|integer|exists:pathogens,id',
            'veterinarian_id' => 'nullable|integer|exists:users,id',
            'diagnosis_date' => 'nullable|date',
            'status' => 'required|string|in:CONFIRMED_POSITIVE,IN_TREATMENT,RESOLVED,SUSPECTED',
            'treatment_notes' => 'nullable|string',
            'source_context' => 'nullable|string',
        ]);

        $validated['caravan_id'] = $caravanId;
        $dto = CreateVeterinaryDiagnosisDTO::fromArray($validated);
        $diagnosis = $useCase($dto);

        return (new VeterinaryDiagnosisResource($diagnosis))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Mark a diagnosis as resolved (discharge / alta médica).
     */
    public function resolveDiagnosis(
        Request $request,
        int $id,
        ResolveVeterinaryDiagnosisUseCase $useCase
    ): JsonResponse {
        $validated = $request->validate([
            'resolution_date' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);

        $validated['diagnosis_id'] = $id;
        $dto = ResolveVeterinaryDiagnosisDTO::fromArray($validated);
        $success = $useCase($dto);

        return response()->json([
            'success' => $success,
            'message' => $success ? 'Alta médica registrada con éxito.' : 'Error al registrar el alta médica.',
        ]);
    }
}
