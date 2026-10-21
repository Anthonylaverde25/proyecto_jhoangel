<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\DTOs\Caravans\TagCalvesDTO;
use App\Application\UseCases\Caravans\ListUntaggedCalvesUseCase;
use App\Application\UseCases\Caravans\TagCalvesUseCase;
use App\Http\Controllers\Controller;
use App\Http\Requests\Caravans\TagCalvesRequest;
use App\Http\Resources\CaravanResource;
use Illuminate\Http\JsonResponse;

/**
 * "Caravanear crías": the calves born without a tag, and the load that tags them.
 */
class TagCalvesController extends Controller
{
    public function __construct(
        private readonly ListUntaggedCalvesUseCase $listUntaggedCalves,
        private readonly TagCalvesUseCase $tagCalves
    ) {
    }

    public function index(int $batchId): JsonResponse
    {
        return response()->json(['data' => ($this->listUntaggedCalves)($batchId)]);
    }

    public function store(TagCalvesRequest $request): JsonResponse
    {
        $tagged = ($this->tagCalves)(TagCalvesDTO::fromArray($request->validated()));

        return response()->json([
            'message' => count($tagged) === 1 ? 'Cría caravaneada.' : count($tagged) . ' crías caravaneadas.',
            'data' => CaravanResource::collection($tagged),
        ]);
    }
}
