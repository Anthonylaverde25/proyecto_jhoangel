<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\UseCases\Activities\ListAvailableActivitiesUseCase;
use App\Application\UseCases\Activities\ToggleCompanyActivityUseCase;
use App\Http\Controllers\Controller;
use App\Http\Resources\ActivityResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Http\Requests\Activities\ToggleActivityRequest;

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
}
