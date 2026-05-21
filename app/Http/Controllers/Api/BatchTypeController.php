<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\UseCases\BatchTypes\ListBatchTypesUseCase;
use App\Http\Controllers\Controller;
use App\Http\Resources\BatchTypeResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BatchTypeController extends Controller
{
    /**
     * List all active batch types for the company.
     *
     * @param Request $request
     * @param ListBatchTypesUseCase $useCase
     * @return JsonResponse
     */
    public function index(Request $request, ListBatchTypesUseCase $useCase): JsonResponse
    {
        $companyId = (int) $request->header('X-Company-ID');
        $batchTypes = $useCase($companyId);

        return response()->json(BatchTypeResource::collection($batchTypes));
    }
}
