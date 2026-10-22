<?php

declare(strict_types=1);

namespace App\Infrastructure\OCR;

use App\Core\Exceptions\FiscalReaderUnavailableException;
use App\Core\Interfaces\IFiscalItemMatcher;
use App\Core\ValueObjects\FiscalCatalogItem;
use App\Core\ValueObjects\FiscalItemMatch;
use App\Core\ValueObjects\FiscalLineRead;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Asks the ai-agent (POST /api/v1/fiscal-documents/match-items) which catalog item each line is, as
 * AiAgentFiscalDocumentReader asks who issued the document. Text only: neither the paper's codes nor
 * ours are sent (Delivery G, A1).
 */
class AiAgentFiscalItemMatcher implements IFiscalItemMatcher
{
    public function match(array $lines, array $catalog, array $providerItemIds, int $companyId): array
    {
        $url = rtrim((string) config('services.ai_agent.url', 'http://localhost:8001'), '/') . '/api/v1/fiscal-documents/match-items';

        try {
            $response = Http::withHeaders(['X-Company-ID' => (string) $companyId, 'Accept' => 'application/json'])
                ->timeout(60)
                ->connectTimeout(10)
                ->post($url, [
                    'lines' => array_map(fn (FiscalLineRead $l) => ['position' => $l->position, 'description' => $l->description], $lines),
                    'catalog' => array_map(fn (FiscalCatalogItem $i) => [
                        'id' => $i->id,
                        'name' => $i->name,
                        'type' => $i->type,
                        'unit' => $i->unit,
                        'category' => $i->category,
                        'description' => $i->description,
                        'presentations' => $i->presentations,
                    ], $catalog),
                    'provider_item_ids' => array_values($providerItemIds),
                ]);
        } catch (ConnectionException $e) {
            Log::error('[AiAgentFiscalItemMatcher] ai-agent unreachable', ['error' => $e->getMessage()]);
            throw FiscalReaderUnavailableException::unreachable();
        }

        if ($response->failed()) {
            $detail = (string) ($response->json('detail') ?? $response->json('message') ?? '');
            Log::error('[AiAgentFiscalItemMatcher] ai-agent error', ['status' => $response->status(), 'detail' => $detail]);
            throw FiscalReaderUnavailableException::failed($detail);
        }

        Log::info('[AiAgentFiscalItemMatcher] matched', ['company' => $companyId, 'stats' => $response->json('stats')]);

        $matches = [];
        foreach ((array) $response->json('matches', []) as $entry) {
            if (!is_array($entry) || !isset($entry['position'])) {
                continue;
            }
            $level = in_array($entry['level'] ?? null, [FiscalItemMatch::HIGH, FiscalItemMatch::MEDIUM], true)
                ? (string) $entry['level']
                : FiscalItemMatch::NONE;
            $matches[] = new FiscalItemMatch(
                (int) $entry['position'],
                $level !== FiscalItemMatch::NONE && isset($entry['item_id']) ? (int) $entry['item_id'] : null,
                $level,
                is_string($entry['reason'] ?? null) ? $entry['reason'] : null,
            );
        }

        return $matches;
    }
}
