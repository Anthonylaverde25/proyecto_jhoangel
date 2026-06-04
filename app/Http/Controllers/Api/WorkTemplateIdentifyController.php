<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\UseCases\WorkTemplates\WorkTemplateUseCases;
use App\Core\Interfaces\ICompanyContext;
use App\Http\Controllers\Controller;
use App\Http\Resources\WorkTemplateResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WorkTemplateIdentifyController extends Controller
{
    public function __construct(
        private readonly WorkTemplateUseCases $useCases,
        private readonly ICompanyContext $companyContext
    ) {
    }

    /**
     * Handle the incoming request to identify a work template via OCR.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'document' => 'required|file|mimes:pdf,png,jpg,jpeg,tiff|max:20480',
            'provider' => 'nullable|string|in:azure,google',
        ]);

        $companyId = $this->companyContext->getCompanyId();

        if (!$companyId) {
            return response()->json(['message' => 'No company context found'], 403);
        }

        try {
            $file = $request->file('document');
            $provider = $request->input('provider');

            $result = ($this->useCases->identifyTemplate)($file, $companyId, $provider);

            $identifiedTemplate = $result['identified_template'];

            return response()->json([
                'status' => 'success',
                'identified_template' => $identifiedTemplate ? new WorkTemplateResource($identifiedTemplate) : null,
                'context' => $result['context'],
                'suggested_workday_code' => $result['suggested_workday_code'],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Template identification failed: ' . $e->getMessage()
            ], 500);
        }
    }
}
