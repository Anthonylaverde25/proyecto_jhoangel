<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\DTOs\ImportCaravansDTO;
use App\Application\UseCases\Caravans\CaravanUseCases;
use App\Http\Controllers\Controller;
use App\Http\Requests\Caravans\ImportCaravansRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ImportCaravansController extends Controller
{
    public function __construct(
        private readonly CaravanUseCases $caravan,
    ) {
    }

    /**
     * Import mapped rows from OCR analysis into the caravans table.
     *
     * @param ImportCaravansRequest $request
     * @return JsonResponse
     */
    public function __invoke(ImportCaravansRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $dto = new ImportCaravansDTO(
            rows: $validated['rows'],
            targetModel: 'caravans',
            workType: $validated['work_type'] ?? 'entry',
            batchId: $validated['batch_id'] ?? null,
            farmId: $validated['farm_id'] ?? null,
            batchName: $validated['batch_name'] ?? null,
            emptyDestinationBatchId: $validated['empty_destination_batch_id'] ?? null,
            serviceOrderId: $validated['service_order_id'] ?? null,
        );

        $result = ($this->caravan->import)($dto);

        $statusCode = $result['imported'] > 0 ? 201 : 422;

        return response()->json([
            'status' => $result['imported'] > 0 ? 'success' : 'error',
            'message' => sprintf(
                '%d imported, %d skipped, %d errors.',
                $result['imported'],
                $result['skipped'],
                count($result['errors'])
            ),
            'data' => $result,
        ], $statusCode);
    }
}
