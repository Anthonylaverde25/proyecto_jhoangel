<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\DTOs\ImportCaravansDTO;
use App\Application\UseCases\Caravans\CaravanUseCases;
use App\Http\Controllers\Controller;
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
     * @param Request $request
     * @return JsonResponse
     */
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'rows'                  => 'required|array|min:1',
            'rows.*.identification' => 'required|string',
            'rows.*.category'       => 'nullable|string',
            'rows.*.teeth'          => 'nullable|string',
            'rows.*.entry_weight'   => 'nullable|string',
            'rows.*.exit_weight'    => 'nullable|string',
            'rows.*.breed'          => 'nullable|string',
            'rows.*.sex'            => 'nullable|string',
            'rows.*.entry_date'     => 'nullable|string',
            'work_type'             => 'nullable|string|in:entry,update,exit',
        ]);

        $dto = new ImportCaravansDTO(
            rows: $validated['rows'],
            targetModel: 'caravans',
            workType: $validated['work_type'] ?? 'entry',
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
