<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\DTOs\Lser01\Lser01SubmissionDTO;
use App\Application\UseCases\WorkTemplates\WorkTemplateUseCases;
use App\Core\Exceptions\DomainException;
use App\Core\Exceptions\Lser01ValidationException;
use App\Core\Interfaces\ICompanyContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\WorkTemplates\ProcessLser01Request;
use Illuminate\Http\JsonResponse;

final class ProcessLser01Controller extends Controller
{
    public function __construct(
        private readonly WorkTemplateUseCases $useCases,
        private readonly ICompanyContext $companyContext
    ) {
    }

    public function __invoke(ProcessLser01Request $request): JsonResponse
    {
        $companyId = $this->companyContext->getCompanyId();

        if (!$companyId) {
            return response()->json(['message' => 'No company context found'], 403);
        }

        try {
            $dto = Lser01SubmissionDTO::fromArray($request->validated(), $companyId);
            $result = ($this->useCases->processLser01)($dto);
        } catch (Lser01ValidationException $e) {
            return response()->json([
                'status' => 'invalid',
                'message' => $e->getMessage(),
                'header_errors' => $e->getHeaderErrors(),
                'row_errors' => $e->getRowErrors(),
            ], 422);
        } catch (DomainException $e) {
            return response()->json([
                'status' => 'invalid',
                'message' => $e->getMessage(),
                'header_errors' => [],
                'row_errors' => [],
            ], 422);
        }

        $batch = $result['batch'];

        return response()->json([
            'status' => 'success',
            'message' => "Lote de servicio '{$batch->getName()}' creado con su orden {$result['service_order_code']}.",
            'data' => [
                'batch_id' => $batch->getId(),
                'batch_name' => $batch->getName(),
                'service_order_id' => $result['service_order_id'],
                'service_order_code' => $result['service_order_code'],
                'females_count' => $result['females_count'],
            ],
        ], 201);
    }
}
