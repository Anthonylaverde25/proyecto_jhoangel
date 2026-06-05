<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\DTOs\ImportOCRGestationDiagnosisDTO;
use App\Application\UseCases\Caravans\ImportGestationDiagnosisFromOCRUseCase;
use App\Core\Interfaces\ICompanyContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Caravans\ImportOCRGestationRequest;
use Illuminate\Http\JsonResponse;

/**
 * Endpoint for importing gestation diagnoses from OCR-processed REP-01 data.
 *
 * This controller is specifically designed for the REP-01 workflow where
 * caravans already exist and we are evaluating their reproductive status,
 * NOT creating new caravans.
 */
final class ImportOCRGestationController extends Controller
{
    public function __construct(
        private readonly ImportGestationDiagnosisFromOCRUseCase $useCase,
        private readonly ICompanyContext $companyContext
    ) {
    }

    /**
     * Handle the incoming REP-01 OCR import request.
     *
     * @param ImportOCRGestationRequest $request
     * @return JsonResponse
     */
    public function __invoke(ImportOCRGestationRequest $request): JsonResponse
    {
        $companyId = $this->companyContext->getCompanyId();

        if (!$companyId) {
            return response()->json(['message' => 'No company context found.'], 403);
        }

        $validated = $request->validated();

        $dto = new ImportOCRGestationDiagnosisDTO(
            rows: $validated['rows'],
            serviceOrderId: (int) $validated['service_order_id'],
            diagnosisDate: $validated['diagnosis_date'] ?? null,
            emptyDestinationBatchId: isset($validated['empty_cows_batch_id']) ? (int) $validated['empty_cows_batch_id'] : null,
        );

        try {
            $result = ($this->useCase)($dto, $companyId);

            $statusCode = $result['processed'] > 0 ? 201 : 422;

            return response()->json([
                'status'  => $result['processed'] > 0 ? 'success' : 'error',
                'message' => sprintf(
                    '%d diagnoses processed, %d skipped, %d errors.',
                    $result['processed'],
                    $result['skipped'],
                    count($result['errors'])
                ),
                'data' => $result,
            ], $statusCode);

        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Gestation diagnosis import failed: ' . $e->getMessage(),
            ], 500);
        }
    }
}
