<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\DTOs\Veterinary\RegisterSampleShipmentDTO;
use App\Application\UseCases\Veterinary\CorrectSampleShipmentUseCase;
use App\Application\UseCases\Veterinary\RegisterSampleShipmentUseCase;
use App\Application\UseCases\Veterinary\VoidSampleShipmentUseCase;
use App\Core\Interfaces\ISampleShipmentRepository;
use App\Core\Interfaces\IVeterinaryPortalContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Veterinary\RegisterSampleShipmentRequest;
use App\Http\Resources\SampleShipmentResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * ADR-30 / ADR-36: the professional's dispatch desk.
 *
 * It is not scoped to an act on purpose: you pack a box, not a chute session, so the screen
 * that builds one shows every tube still in the professional's hands across all their acts.
 */
class SampleShipmentController extends Controller
{
    /**
     * Everything the professional still holds. The answer to "what do I have here?", which is
     * the question somebody actually asks while filling a cooler.
     */
    public function pendingTubes(
        ISampleShipmentRepository $shipments,
        IVeterinaryPortalContext $portalContext
    ): JsonResponse {
        return response()->json([
            'data' => $shipments->findPendingTubes(
                $portalContext->getVeterinarianId(),
                $portalContext->getCompanyId()
            ),
        ]);
    }

    public function index(
        ISampleShipmentRepository $shipments,
        IVeterinaryPortalContext $portalContext
    ): AnonymousResourceCollection {
        return SampleShipmentResource::collection(
            $shipments->findAll($portalContext->getCompanyId(), $portalContext->getVeterinarianId())
        );
    }

    public function store(
        RegisterSampleShipmentRequest $request,
        RegisterSampleShipmentUseCase $useCase,
        IVeterinaryPortalContext $portalContext
    ): JsonResponse {
        $payload = $request->validated();
        $payload['company_id'] = $portalContext->getCompanyId();
        $payload['veterinarian_id'] = $portalContext->getVeterinarianId();
        $payload['declared_by_user_id'] = $portalContext->getUserId();
        // Taken from the resolved session, never from the body: the snapshot is the point.
        $payload['declared_by_name'] = $portalContext->getVeterinarianName();

        return (new SampleShipmentResource($useCase(RegisterSampleShipmentDTO::fromArray($payload))))
            ->response()
            ->setStatusCode(201);
    }

    /** ADR-37: free correction while nobody has cited this box. */
    public function update(
        int $id,
        RegisterSampleShipmentRequest $request,
        CorrectSampleShipmentUseCase $useCase,
        IVeterinaryPortalContext $portalContext
    ): JsonResponse {
        return (new SampleShipmentResource(
            $useCase($id, $portalContext->getCompanyId(), $request->validated())
        ))->response();
    }

    /** ADR-37: after that, void with a reason and reissue. Nothing is erased. */
    public function void(
        int $id,
        Request $request,
        VoidSampleShipmentUseCase $useCase,
        IVeterinaryPortalContext $portalContext
    ): JsonResponse {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        return (new SampleShipmentResource($useCase(
            $id,
            $portalContext->getCompanyId(),
            (string) $validated['reason'],
            $portalContext->getUserId()
        )))->response();
    }

    /**
     * §3.9: institutions are suggested from what has already been recorded. Nobody registers a
     * laboratory; the tenth time somebody types "Rosario" it comes back with its CUIT.
     */
    public function institutionSuggestions(
        Request $request,
        ISampleShipmentRepository $shipments,
        IVeterinaryPortalContext $portalContext
    ): JsonResponse {
        return response()->json([
            'data' => $shipments->findInstitutionSuggestions(
                $portalContext->getCompanyId(),
                (string) $request->query('search', '')
            ),
        ]);
    }
}
