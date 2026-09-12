<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\DTOs\Veterinary\ProcessVetPortalEvaluationDTO;
use App\Application\DTOs\Veterinary\ProtocolAttachmentUploadDTO;
use App\Application\DTOs\Veterinary\RegisterLabReportDTO;
use App\Application\DTOs\Veterinary\SignExtractionActDTO;
use App\Application\UseCases\Veterinary\GetVeterinaryPortalWorkspaceUseCase;
use App\Application\UseCases\Veterinary\ProcessVetPortalEvaluationUseCase;
use App\Application\UseCases\Veterinary\RegisterLabReportUseCase;
use App\Application\UseCases\Veterinary\SignExtractionActUseCase;
use App\Application\Services\PersistProtocolDetailsService;
use App\Core\Entities\DiagnosticProtocolEntity;
use App\Core\Enums\SampleDestinationPlan;
use App\Core\Exceptions\VeterinaryDomainException;
use App\Core\Interfaces\IDiagnosticProtocolRepository;
use App\Core\Interfaces\IVeterinaryPortalContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Veterinary\ProcessVetPortalEvaluationRequest;
use App\Http\Requests\Veterinary\RegisterLabReportRequest;
use App\Http\Requests\Veterinary\SignExtractionActRequest;
use App\Http\Requests\Veterinary\UpdateExtractionActDestinationPlanRequest;
use App\Http\Resources\DiagnosticProtocolResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Use Case 1 surface: the professional works the chute directly.
 *
 * Serves both entry points unchanged — an internal user with the `veterinarian` role and an
 * external professional on a temporary link — because ResolveVeterinaryPortalAccess has
 * already collapsed both into IVeterinaryPortalContext.
 */
class VeterinaryPortalController extends Controller
{
    /**
     * Session bootstrap: who am I, and which troop may I work on.
     */
    public function session(
        IVeterinaryPortalContext $portalContext,
        \App\Core\Interfaces\IVeterinarianRepository $veterinarians
    ): JsonResponse
    {
        return response()->json([
            'data' => [
                // ADR-38: both CUITs travel with the session — the attachment rule compares
                // the analysing institution against them.
                'veterinarian' => [
                    'id' => $portalContext->getVeterinarianId(),
                    'name' => $portalContext->getVeterinarianName(),
                    'license_number' => $portalContext->getLicenseNumber(),
                    'cuit' => $veterinarians->findById($portalContext->getVeterinarianId(), $portalContext->getCompanyId())?->getCuit(),
                    'billing_cuit' => $veterinarians->findById($portalContext->getVeterinarianId(), $portalContext->getCompanyId())?->getBillingCuit(),
                ],
                'access_mode' => $portalContext->getAccessMode()?->value,
                // A management session sees everything and writes nothing; the UI has to say so.
                'is_read_only' => $portalContext->isReadOnly(),
                'company_id' => $portalContext->getCompanyId(),
                'allowed_batch_ids' => $portalContext->getAllowedBatchIds(),
                'scoped_to_act_ids' => $portalContext->getAllowedProtocolIds(),
            ],
        ]);
    }

    public function workspace(Request $request, GetVeterinaryPortalWorkspaceUseCase $useCase): JsonResponse
    {
        $batchId = $request->has('batch_id') ? (int) $request->query('batch_id') : null;

        return response()->json(['data' => $useCase($batchId)]);
    }

    public function storeEvaluation(
        ProcessVetPortalEvaluationRequest $request,
        ProcessVetPortalEvaluationUseCase $useCase,
        IVeterinaryPortalContext $portalContext
    ): JsonResponse {
        $batchId = (int) $request->validated('batch_id');

        // Re-checked here as well as in the use case: authorisation must not depend on the
        // client having sent an honest batch id.
        if (!$portalContext->canAccessBatch($batchId)) {
            throw VeterinaryDomainException::batchNotAssigned($batchId);
        }

        $payload = $request->validated();
        $payload['company_id'] = $portalContext->getCompanyId();
        $payload['veterinarian_id'] = $portalContext->getVeterinarianId();
        $payload['created_by_user_id'] = $portalContext->getUserId();
        $payload['access_token_id'] = $portalContext->getAccessTokenId();

        $dto = ProcessVetPortalEvaluationDTO::fromArray($payload);

        return (new DiagnosticProtocolResource($useCase($dto)))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * The professional's inbox: acts of theirs awaiting a signature, and signed acts whose tubes
     * are still waiting on the laboratory.
     */
    public function pendingActs(
        IDiagnosticProtocolRepository $protocols,
        IVeterinaryPortalContext $portalContext
    ): JsonResponse {
        $companyId = $portalContext->getCompanyId();
        $veterinarianId = $portalContext->getVeterinarianId();
        $allowed = $portalContext->getAllowedProtocolIds();

        $filter = static fn (array $acts): array => $allowed === []
            ? $acts
            : array_values(array_filter(
                $acts,
                static fn ($act): bool => in_array((int) $act->getId(), $allowed, true)
            ));

        return response()->json([
            'data' => [
                'pending_signature' => DiagnosticProtocolResource::collection(
                    $filter($protocols->findActsPendingSignature($veterinarianId, $companyId))
                ),
                'pending_lab_report' => DiagnosticProtocolResource::collection(
                    $filter($protocols->findActsPendingLabReport($veterinarianId, $companyId))
                ),
            ],
        ]);
    }

    public function showAct(
        int $id,
        IDiagnosticProtocolRepository $protocols,
        IVeterinaryPortalContext $portalContext
    ): JsonResponse {
        if (!$portalContext->canAccessAct($id)) {
            throw VeterinaryDomainException::domainError('El enlace de acceso no habilita esta acta.');
        }

        $act = $protocols->findById($id, $portalContext->getCompanyId());

        if ($act === null || $act->getVeterinarianId() !== $portalContext->getVeterinarianId()) {
            throw VeterinaryDomainException::actNotFound($id);
        }

        return (new DiagnosticProtocolResource($act))->response();
    }

    /**
     * ADR-13: the professional closes the chain of custody. From here the act is immutable.
     */
    public function signAct(
        int $id,
        SignExtractionActRequest $request,
        SignExtractionActUseCase $useCase,
        IVeterinaryPortalContext $portalContext
    ): JsonResponse {
        if (!$portalContext->canAccessAct($id)) {
            throw VeterinaryDomainException::domainError('El enlace de acceso no habilita esta acta.');
        }

        $payload = $request->validated();
        $payload['act_id'] = $id;
        $payload['company_id'] = $portalContext->getCompanyId();
        $payload['veterinarian_id'] = $portalContext->getVeterinarianId();
        $payload['signed_by_user_id'] = $portalContext->getUserId();
        $payload['access_token_id'] = $portalContext->getAccessTokenId();

        return (new DiagnosticProtocolResource($useCase(SignExtractionActDTO::fromArray($payload))))
            ->response()
            ->setStatusCode(200);
    }

    /**
     * ADR-40: Update the destination plan of an unsigned extraction act.
     */
    public function updateDestinationPlan(
        int $id,
        UpdateExtractionActDestinationPlanRequest $request,
        IDiagnosticProtocolRepository $protocols,
        PersistProtocolDetailsService $detailsService,
        IVeterinaryPortalContext $portalContext
    ): JsonResponse {
        if (!$portalContext->canAccessAct($id)) {
            throw VeterinaryDomainException::domainError('El enlace de acceso no habilita esta acta.');
        }

        $act = $protocols->findById($id, $portalContext->getCompanyId());

        if ($act === null || $act->getVeterinarianId() !== $portalContext->getVeterinarianId()) {
            throw VeterinaryDomainException::actNotFound($id);
        }

        if ($act->isSigned()) {
            throw VeterinaryDomainException::domainError('El plan de destino queda congelado tras firmar el acta.');
        }

        $plan = SampleDestinationPlan::from((string) $request->validated('destination_plan'));

        DB::transaction(function () use ($detailsService, $act, $plan): void {
            $detailsService->upsertActDetail(
                protocolId: (int) $act->getId(),
                institution: $act->getActInstitution(),
                destinationPlan: $plan,
                dispatchNoteNumber: $act->getDispatchNoteNumber(),
                dispatchedAt: $act->getDispatchedAt(),
            );
        });

        $updated = $protocols->findById($id, $portalContext->getCompanyId());

        return (new DiagnosticProtocolResource($updated))
            ->response()
            ->setStatusCode(200);
    }

    /**
     * ADR-11: the laboratory reports, as its own signed document hanging off the act.
     */
    public function storeLabReport(
        int $id,
        RegisterLabReportRequest $request,
        RegisterLabReportUseCase $useCase,
        IVeterinaryPortalContext $portalContext
    ): JsonResponse {
        if (!$portalContext->canAccessAct($id)) {
            throw VeterinaryDomainException::domainError('El enlace de acceso no habilita esta acta.');
        }

        $payload = $request->validated();
        $payload['extraction_act_id'] = $id;
        $payload['company_id'] = $portalContext->getCompanyId();
        $payload['veterinarian_id'] = $portalContext->getVeterinarianId();
        $payload['created_by_user_id'] = $portalContext->getUserId();
        $payload['access_token_id'] = $portalContext->getAccessTokenId();
        // ADR-27: the external laboratory's own PDF, read out of the HTTP layer here so the use
        // case never depends on `Illuminate\Http\UploadedFile`.
        $payload['attachments'] = $this->readUploads($request);

        return (new DiagnosticProtocolResource($useCase(RegisterLabReportDTO::fromArray($payload))))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * @return list<ProtocolAttachmentUploadDTO>
     */
    private function readUploads(Request $request): array
    {
        $uploads = [];

        /** @var UploadedFile $file */
        foreach ((array) $request->file('attachments', []) as $file) {
            $uploads[] = new ProtocolAttachmentUploadDTO(
                fileName: $file->getClientOriginalName(),
                mimeType: (string) ($file->getMimeType() ?? 'application/octet-stream'),
                extension: (string) ($file->getClientOriginalExtension() ?: 'bin'),
                sizeBytes: (int) $file->getSize(),
                contents: (string) file_get_contents($file->getRealPath())
            );
        }

        return $uploads;
    }
}
