<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\DTOs\RegisterBatchWeighingDTO;
use App\Application\UseCases\WeighingOrders\ListBatchWeighingsUseCase;
use App\Application\UseCases\WeighingOrders\RegisterBatchWeighingUseCase;
use App\Core\Exceptions\WeighingOrderDomainException;
use App\Core\Interfaces\ICompanyContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\WeighingOrders\RegisterBatchWeighingRequest;
use App\Http\Resources\WeighingOrderResource;
use Illuminate\Http\JsonResponse;

class WeighingOrderController extends Controller
{
    public function __construct(
        private readonly ICompanyContext $companyContext,
    ) {
    }

    public function registerBatch(RegisterBatchWeighingRequest $request, RegisterBatchWeighingUseCase $useCase): JsonResponse
    {
        try {
            $dto = RegisterBatchWeighingDTO::fromArray($request->validated(), $this->companyId(), $request->user()?->id);
            $order = $useCase($dto);
        } catch (WeighingOrderDomainException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->getErrorCode()], 422);
        }

        return response()->json(new WeighingOrderResource($order), 201);
    }

    public function byBatch(int $batchId, ListBatchWeighingsUseCase $useCase): JsonResponse
    {
        return response()->json(WeighingOrderResource::collection($useCase($batchId, $this->companyId())));
    }

    private function companyId(): int
    {
        return (int) $this->companyContext->getCompanyId();
    }
}
