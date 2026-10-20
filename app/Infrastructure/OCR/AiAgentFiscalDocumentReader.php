<?php

declare(strict_types=1);

namespace App\Infrastructure\OCR;

use App\Core\Exceptions\FiscalReaderUnavailableException;
use App\Core\Interfaces\IFiscalDocumentReader;
use App\Core\ValueObjects\FiscalIssuerRead;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Forwards a provider document to the ai-agent (POST /api/v1/fiscal-documents/read), the same way
 * AiAgentOCRProvider forwards worksheets.
 */
class AiAgentFiscalDocumentReader implements IFiscalDocumentReader
{
    public function readIssuer(string $contents, string $fileName, string $mimeType, int $companyId): FiscalIssuerRead
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(220);
        }

        $url = rtrim((string) config('services.ai_agent.url', 'http://localhost:8001'), '/') . '/api/v1/fiscal-documents/read';

        try {
            $response = Http::withHeaders(['X-Company-ID' => (string) $companyId, 'Accept' => 'application/json'])
                ->timeout(200)
                ->connectTimeout(10)
                ->attach('document', $contents, $fileName, ['Content-Type' => $mimeType])
                ->post($url);
        } catch (ConnectionException $e) {
            Log::error('[AiAgentFiscalDocumentReader] ai-agent unreachable', ['error' => $e->getMessage()]);
            throw FiscalReaderUnavailableException::unreachable();
        }

        if ($response->failed()) {
            $detail = (string) ($response->json('detail') ?? $response->json('message') ?? '');
            Log::error('[AiAgentFiscalDocumentReader] ai-agent error', ['status' => $response->status(), 'detail' => $detail]);
            throw FiscalReaderUnavailableException::failed($detail);
        }

        return FiscalIssuerRead::fromAgentPayload((array) $response->json());
    }
}
