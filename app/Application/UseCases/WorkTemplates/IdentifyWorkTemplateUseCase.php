<?php

declare(strict_types=1);

namespace App\Application\UseCases\WorkTemplates;

use App\Core\Services\WorkdayCodeGenerator;
use App\Infrastructure\OCR\AiAgentOCRProvider;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

/**
 * Identify a work template from a document using the AI Agent microservice.
 *
 * The sole OCR provider is the AiAgentOCRProvider, which delegates all vision
 * analysis to the internal FastAPI microservice (Gemini Vision).
 * The microservice is responsible for:
 *  - Detecting the template code from the image.
 *  - Fetching the template's schema_definition from the tenant DB.
 *  - Extracting all header fields and table rows guided by the schema.
 *
 * This use case receives the structured result and returns it directly to the
 * controller, ready for the frontend's editable confirmation datatable.
 */
final class IdentifyWorkTemplateUseCase
{
    public function __construct(
        private readonly WorkdayCodeGenerator $workdayCodeGenerator,
    ) {
    }

    /**
     * Identify a work template from a document image using the AI Agent microservice.
     *
     * @param UploadedFile $file      The uploaded worksheet image.
     * @param int          $companyId The active company ID (sent as X-Company-ID to ai-agent).
     * @return array                  Structured result ready for the frontend.
     *
     * @throws \RuntimeException      If the microservice is unreachable or returns an error.
     */
    public function __invoke(UploadedFile $file, int $companyId): array
    {
        Log::info('[IdentifyWorkTemplateUseCase] Delegating image analysis to ai-agent microservice', [
            'company_id' => $companyId,
            'file_name'  => $file->getClientOriginalName(),
        ]);

        $provider = new AiAgentOCRProvider($companyId);
        $result   = $provider->analyze($file);

        Log::info('[IdentifyWorkTemplateUseCase] AI Agent analysis complete', [
            'template' => $result['identified_template']['code'] ?? 'unknown',
            'rows'     => $result['data'][0]['total_detected'] ?? 0,
        ]);

        return [
            'identified_template'    => $result['identified_template'],
            'context'                => $result['context'] ?? [],
            'suggested_workday_code' => $this->workdayCodeGenerator->generateForDate(new \DateTime()),
            'data'                   => $result['data'] ?? [],
        ];
    }
}
