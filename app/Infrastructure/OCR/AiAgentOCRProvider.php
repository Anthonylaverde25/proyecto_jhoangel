<?php

declare(strict_types=1);

namespace App\Infrastructure\OCR;

use App\Core\Interfaces\IOCRProvider;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * AI Agent OCR Provider.
 *
 * Delegates document analysis to the internal FastAPI ai-agent microservice.
 * The ai-agent uses Gemini Vision to:
 *  1. Detect the template code from the image.
 *  2. Fetch the template's schema_definition from the tenant DB.
 *  3. Extract all header fields and table rows guided by the schema.
 *
 * Returns a response payload already shaped for the IdentifyWorkTemplateUseCase:
 *  [
 *    'identified_template' => WorkTemplate entity | null,
 *    'context'             => ['lote' => ..., 'fecha' => ..., ...],
 *    'data'                => [['mapped_rows' => [...], 'total_detected' => N]],
 *  ]
 *
 * This provider BYPASSES the legacy Azure pipeline and the intermediate
 * field-mapping/normalization layers in the use case because the ai-agent
 * already returns mapped_rows with {key: {value, confidence}} structure.
 */
class AiAgentOCRProvider implements IOCRProvider
{
    private string $baseUrl;
    private int $companyId;

    public function __construct(int $companyId = 0)
    {
        $this->baseUrl  = rtrim((string) config('services.ai_agent.url', 'http://localhost:8001'), '/');
        $this->companyId = $companyId;
    }

    /**
     * Analyze a document by forwarding it to the ai-agent microservice.
     *
     * The microservice endpoint POST /api/v1/templates/analyze:
     *  - Detects the template code from the image via Gemini Vision.
     *  - Fetches the schema_definition from the tenant database.
     *  - Extracts all header fields and table rows guided by the schema.
     *
     * @param UploadedFile $file  The uploaded worksheet image.
     * @return array              The raw response from the microservice.
     *
     * @throws \RuntimeException  If the microservice is unreachable or returns an error.
     */
    public function analyze(UploadedFile $file): array
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(220);
        }

        if (!$this->baseUrl) {
            throw new \RuntimeException('AI Agent microservice URL is not configured. Set AI_AGENT_URL in .env');
        }

        Log::info('[AiAgentOCRProvider] Forwarding worksheet to ai-agent', [
            'url'        => "{$this->baseUrl}/api/v1/templates/analyze",
            'company_id' => $this->companyId,
            'file_name'  => $file->getClientOriginalName(),
            'file_size'  => $file->getSize(),
            'mime_type'  => $file->getMimeType(),
        ]);

        $fileContents = file_get_contents($file->getRealPath());
        if ($fileContents === false || $fileContents === '') {
            $fileContents = 'file_data';
        }

        $response = Http::withHeaders([
            'X-Company-ID' => (string) $this->companyId,
            'Accept'       => 'application/json',
        ])
            ->timeout(200)
            ->connectTimeout(10)
            ->attach('document', $fileContents, $file->getClientOriginalName(), [
                'Content-Type' => $file->getMimeType() ?? 'image/png',
            ])
            ->post("{$this->baseUrl}/api/v1/templates/analyze");

        if ($response->failed()) {
            $errorBody = $response->json() ?? ['detail' => $response->body()];
            Log::error('[AiAgentOCRProvider] Microservice returned error', [
                'status' => $response->status(),
                'body'   => $errorBody,
            ]);
            throw new \RuntimeException(
                'Template identification failed: ' . ($errorBody['detail'] ?? $errorBody['message'] ?? 'Unknown error from ai-agent')
            );
        }

        $payload = $response->json();

        Log::info('[AiAgentOCRProvider] Received response from ai-agent', [
            'status'    => $payload['status'] ?? 'unknown',
            'template'  => $payload['identified_template']['code'] ?? 'none',
            'row_count' => isset($payload['data'][0]['total_detected']) ? $payload['data'][0]['total_detected'] : 0,
        ]);

        // Transform the ai-agent response to the format expected by IdentifyWorkTemplateUseCase.
        // Since the ai-agent already handles detection, schema loading and extraction,
        // we return the payload directly. The use case will skip the OCR normalization pipeline
        // when it detects the 'ai_agent_processed' flag.
        return [
            'ai_agent_processed'   => true,
            'identified_template'  => $payload['identified_template'] ?? null,
            'context'              => $payload['context'] ?? [],
            'data'                 => $payload['data'] ?? [],
            'detection_confidence' => $payload['detection_confidence'] ?? 0.0,
            'status'               => $payload['status'] ?? 'unknown',
            // Legacy fields expected by downstream services (keep empty to avoid confusion)
            'tables'               => [],
            'metadata'             => [],
        ];
    }
}
