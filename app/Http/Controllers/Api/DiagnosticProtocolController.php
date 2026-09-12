<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\DTOs\Veterinary\CreateDiagnosticProtocolDTO;
use App\Application\DTOs\Veterinary\ProtocolAttachmentUploadDTO;
use App\Application\DTOs\Veterinary\VoidDiagnosticProtocolDTO;
use App\Application\UseCases\Veterinary\CreateDiagnosticProtocolWithResultsUseCase;
use App\Application\UseCases\Veterinary\GetDiagnosticProtocolUseCase;
use App\Application\UseCases\Veterinary\ListDiagnosticProtocolsUseCase;
use App\Application\UseCases\Veterinary\VoidDiagnosticProtocolUseCase;
use App\Core\Interfaces\ICompanyContext;
use App\Core\Interfaces\IDiagnosticProtocolRepository;
use App\Http\Controllers\Controller;
use App\Http\Requests\Veterinary\CreateDiagnosticProtocolRequest;
use App\Http\Requests\Veterinary\VoidDiagnosticProtocolRequest;
use App\Http\Resources\DiagnosticProtocolResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\UploadedFile;

/**
 * Use Case 2 surface: the producer digitises an external laboratory report.
 */
class DiagnosticProtocolController extends Controller
{
    public function index(Request $request, ListDiagnosticProtocolsUseCase $useCase): AnonymousResourceCollection
    {
        $filters = $request->only([
            'status',
            'source_channel',
            'verification_status',
            'veterinarian_id',
            'from_date',
            'to_date',
            'search',
        ]);

        return DiagnosticProtocolResource::collection($useCase($filters));
    }

    /**
     * §11.6 reformulated: dispatched long ago, still silent. A consultable filter, never an
     * automatic alert — the threshold is a policy that lives in configuration.
     */
    public function shippedWithoutReport(
        Request $request,
        IDiagnosticProtocolRepository $protocols,
        ICompanyContext $companyContext
    ): AnonymousResourceCollection {
        $overdueDays = $request->has('overdue_days')
            ? max(0, (int) $request->query('overdue_days'))
            : (int) config('livestock.custody.arrival_overdue_days', 7);

        return DiagnosticProtocolResource::collection(
            $protocols->findActsShippedWithoutReport((int) $companyContext->getCompanyId(), $overdueDays)
        );
    }

    public function show(int $id, GetDiagnosticProtocolUseCase $useCase): JsonResponse
    {
        return (new DiagnosticProtocolResource($useCase($id)))->response();
    }

    public function store(
        CreateDiagnosticProtocolRequest $request,
        CreateDiagnosticProtocolWithResultsUseCase $useCase,
        ICompanyContext $companyContext
    ): JsonResponse {
        $validated = $request->validated();
        $validated['company_id'] = $companyContext->getCompanyId();
        $validated['created_by_user_id'] = $request->user()?->getAuthIdentifier();

        $dto = CreateDiagnosticProtocolDTO::fromArray($validated, $this->readAttachments($request));

        return (new DiagnosticProtocolResource($useCase($dto)))
            ->response()
            ->setStatusCode(201);
    }

    public function void(
        VoidDiagnosticProtocolRequest $request,
        int $id,
        VoidDiagnosticProtocolUseCase $useCase,
        ICompanyContext $companyContext
    ): JsonResponse {
        $dto = new VoidDiagnosticProtocolDTO(
            protocolId: $id,
            companyId: (int) $companyContext->getCompanyId(),
            reason: (string) $request->validated('reason'),
            voidedByUserId: $request->user()?->getAuthIdentifier() !== null
                ? (int) $request->user()->getAuthIdentifier()
                : null
        );

        return (new DiagnosticProtocolResource($useCase($dto)))->response();
    }

    /**
     * Files are read here and only their bytes cross into the application layer, so the use
     * case never depends on the HTTP request lifecycle.
     *
     * @return list<ProtocolAttachmentUploadDTO>
     */
    private function readAttachments(CreateDiagnosticProtocolRequest $request): array
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
