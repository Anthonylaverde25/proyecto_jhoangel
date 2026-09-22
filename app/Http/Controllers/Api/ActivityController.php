<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\DTOs\Activities\ActivityConfigItemDTO;
use App\Application\UseCases\Activities\ListAvailableActivitiesUseCase;
use App\Application\UseCases\Activities\ToggleCompanyActivityUseCase;
use App\Application\UseCases\Activities\UpdateCompanyActivitiesConfigUseCase;
use App\Http\Controllers\Controller;
use App\Http\Requests\Activities\ToggleActivityRequest;
use App\Http\Requests\Activities\UpdateCompanyActivitiesConfigRequest;
use App\Http\Resources\ActivityResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivityController extends Controller
{
    public function index(Request $request, ListAvailableActivitiesUseCase $useCase): JsonResponse
    {
        $companyId = (int) $request->header('X-Company-ID');
        $activities = $useCase($companyId);

        return response()->json(ActivityResource::collection($activities));
    }

    public function toggle(ToggleActivityRequest $request, int $id, ToggleCompanyActivityUseCase $useCase): JsonResponse
    {
        $companyId = (int) $request->header('X-Company-ID');
        $validated = $request->validated();
        $isEnabled = isset($validated['is_enabled']) ? (bool) $validated['is_enabled'] : true;

        $useCase($companyId, $id, $isEnabled);

        return response()->json(['message' => 'Activity status updated successfully']);
    }

    public function updateConfig(
        UpdateCompanyActivitiesConfigRequest $request,
        UpdateCompanyActivitiesConfigUseCase $useCase
    ): JsonResponse {
        $companyId = (int) $request->header('X-Company-ID');
        $validated = $request->validated();

        $items = array_map(
            fn(array $item) => ActivityConfigItemDTO::fromArray($item),
            $validated['activities']
        );

        $useCase($companyId, $items);

        return response()->json(['message' => 'Configuración de flujo de actividades actualizada correctamente']);
    }
}
