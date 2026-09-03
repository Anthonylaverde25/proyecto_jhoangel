<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\UseCases\WorkTemplates\WorkTemplateUseCases;
use App\Core\Interfaces\ICompanyContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use App\Http\Requests\WorkTemplates\IdentifyWorkTemplateRequest;

/**
 * Handles POST /api/work-templates/identify
 *
 * Receives a worksheet image, delegates OCR + structured extraction
 * to the AI Agent microservice via IdentifyWorkTemplateUseCase,
 * and returns the result for the frontend's confirmation datatable.
 */
final class WorkTemplateIdentifyController extends Controller
{
    public function __construct(
        private readonly WorkTemplateUseCases $useCases,
        private readonly ICompanyContext $companyContext
    ) {
    }

    public function __invoke(IdentifyWorkTemplateRequest $request): JsonResponse
    {
        $companyId = $this->companyContext->getCompanyId();

        if (!$companyId) {
            return response()->json(['message' => 'No company context found'], 403);
        }

        try {
            $result = ($this->useCases->identifyTemplate)(
                $request->file('document'),
                $companyId
            );

            return response()->json([
                'status'                 => 'success',
                'identified_template'    => $result['identified_template'],
                'context'                => $result['context'],
                'suggested_workday_code' => $result['suggested_workday_code'],
                'data'                   => $result['data'] ?? [],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Template identification failed: ' . $e->getMessage(),
            ], 500);
        }
    }
}
