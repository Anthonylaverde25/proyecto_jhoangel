<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\DTOs\Ing01\Ing01SubmissionDTO;
use App\Application\UseCases\WorkTemplates\WorkTemplateUseCases;
use App\Core\Interfaces\ICompanyContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\WorkTemplates\ProcessIng01Request;
use Illuminate\Http\JsonResponse;
use Throwable;

final class ProcessIng01Controller extends Controller
{
    public function __construct(
        private readonly WorkTemplateUseCases $useCases,
        private readonly ICompanyContext $companyContext
    ) {
    }

    /**
     * Handle the incoming request to process and persist an ING-01 entry submission.
     *
     * @param ProcessIng01Request $request
     * @return JsonResponse
     */
    public function __invoke(ProcessIng01Request $request): JsonResponse
    {
        $companyId = $this->companyContext->getCompanyId();

        if (!$companyId) {
            return response()->json(['message' => 'No company context found'], 403);
        }

        try {
            $dto = Ing01SubmissionDTO::fromArray($request->validated(), $companyId);
            $result = ($this->useCases->processIng01)($dto);

            return response()->json([
                'status'  => 'success',
                'message' => 'Tropa de ingreso ING-01 procesada y persistida exitosamente.',
                'data'    => $result,
            ], 201);
        } catch (Throwable $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to process ING-01 submission: ' . $e->getMessage(),
            ], 500);
        }
    }
}
