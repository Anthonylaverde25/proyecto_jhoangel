<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\DTOs\AssignSireDTO;
use App\Application\UseCases\Caravans\AssignSireUseCase;
use App\Application\UseCases\Caravans\ListBirthHistoryUseCase;
use App\Application\UseCases\Caravans\ListPendingSireUseCase;
use App\Core\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Caravans\AssignSireRequest;
use App\Http\Resources\BirthHistoryResource;
use App\Models\CaravanLineage;
use App\Http\Resources\PendingSireResource;
use Illuminate\Http\JsonResponse;

final class BirthController extends Controller
{
    public function __construct(
        private readonly ListBirthHistoryUseCase $listBirthHistory,
        private readonly ListPendingSireUseCase $listPendingSire,
        private readonly AssignSireUseCase $assignSire
    ) {
    }

    /**
     * Lista el historial de partos exitosos y estado de lactancia.
     */
    public function index(): JsonResponse
    {
        $entities = ($this->listBirthHistory)();

        return response()->json(
            BirthHistoryResource::collection($entities)
        );
    }

    /**
     * Returns all calves with a pending sire assignment (father_id = null).
     * Used to populate the dashboard pending sires widget.
     */
    public function pendingSires(): JsonResponse
    {
        $models = CaravanLineage::with(['caravan.batch', 'mother', 'gestation'])
            ->whereNull('father_id')
            ->orderBy('birth_date', 'asc')
            ->get();

        return response()->json(
            PendingSireResource::collection($models)
        );
    }

    /**
     * Assigns a sire (father) to a calf after a deferred birth registration.
     * Records the identification method and optional evidence notes.
     *
     * PATCH /api/caravans/{calfId}/assign-sire
     */
    public function assignSire(AssignSireRequest $request, int $calfId): JsonResponse
    {
        try {
            $dto = AssignSireDTO::fromArray([
                'calf_id'               => $calfId,
                'father_id'             => $request->validated('father_id'),
                'identification_method' => $request->validated('identification_method'),
                'sire_notes'            => $request->validated('sire_notes'),
            ]);

            ($this->assignSire)($dto);

            return response()->json(['message' => 'Sire assigned successfully.'], 200);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
