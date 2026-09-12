<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\DTOs\PreService\RegisterBullEvaluationSheetDTO;
use App\Application\DTOs\PreService\RegisterBullHealthEvaluationDTO;
use App\Application\DTOs\Veterinary\CreateVeterinaryDiagnosisDTO;
use App\Application\DTOs\Veterinary\ResolveVeterinaryDiagnosisDTO;
use App\Application\UseCases\PreService\ListPathogensUseCase;
use App\Application\UseCases\PreService\ListPreServiceBullsUseCase;
use App\Application\UseCases\PreService\RegisterBullEvaluationSheetUseCase;
use App\Application\UseCases\PreService\RegisterBullHealthEvaluationUseCase;
use App\Application\UseCases\Veterinary\CreateVeterinaryDiagnosisUseCase;
use App\Application\UseCases\Veterinary\ResolveVeterinaryDiagnosisUseCase;
use App\Core\Interfaces\ICompanyContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\PreService\RegisterBullEvaluationSheetRequest;
use App\Http\Resources\BullHealthEvaluationResource;
use App\Http\Resources\DiagnosticProtocolResource;
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
            // ADR-3: profesional actuante del catálogo, no un usuario del sistema.
            'diagnosis.veterinarian_id' => 'nullable|integer|exists:veterinarians,id',
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
     * Register a whole chute session in one atomic pass: biometry, tubes drawn and the extraction
     * act that gives them a chain of custody.
     *
     * Replaces the per-row loop, which issued one request per bull and silently discarded the
     * sampling checkboxes. ADR-13: this endpoint never signs — the act is born DRAFT and only the
     * acting professional confirms it, from the portal.
     */
    public function registerEvaluationSheet(
        RegisterBullEvaluationSheetRequest $request,
        RegisterBullEvaluationSheetUseCase $useCase,
        ICompanyContext $companyContext
    ): JsonResponse {
        $payload = $request->validated();
        $payload['company_id'] = $companyContext->getCompanyId();
        $payload['registered_by_user_id'] = $request->user()?->getAuthIdentifier();

        $protocol = $useCase(RegisterBullEvaluationSheetDTO::fromArray($payload));

        return (new DiagnosticProtocolResource($protocol))
            ->response()
            ->setStatusCode(201);
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
            // ADR-3: profesional actuante del catálogo, no un usuario del sistema.
            'veterinarian_id' => 'nullable|integer|exists:veterinarians,id',
            'diagnosis_date' => 'nullable|date',
            'status' => 'required|string|in:CONFIRMED_POSITIVE,IN_TREATMENT,RESOLVED,SUSPECTED',
            'treatment_notes' => 'nullable|string',
            'source_context' => 'nullable|string',
        ]);

        $validated['caravan_id'] = $caravanId;
        $validated['diagnosed_by_user_id'] = $request->user()?->getAuthIdentifier();
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
