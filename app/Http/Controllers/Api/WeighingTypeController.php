<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\UseCases\WeighingTypes\ListWeighingTypesUseCase;
use App\Application\UseCases\WeighingTypes\ToggleWeighingTypeUseCase;
use App\Core\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\WeighingTypes\ToggleWeighingTypeRequest;
use App\Http\Resources\WeighingTypeResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WeighingTypeController extends Controller
{
    public function index(Request $request, ListWeighingTypesUseCase $useCase): JsonResponse
    {
        $types = $useCase($request->boolean('only_active'));

        return response()->json(WeighingTypeResource::collection($types));
    }

    public function toggle(ToggleWeighingTypeRequest $request, int $id, ToggleWeighingTypeUseCase $useCase): JsonResponse
    {
        try {
            $type = $useCase($id, (bool) $request->validated('is_active'));
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }

        return response()->json(new WeighingTypeResource($type));
    }
}
