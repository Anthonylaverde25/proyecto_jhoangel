<?php

declare(strict_types=1);

namespace App\Infrastructure\Assistant;

use App\Core\Exceptions\AssistantUnavailableException;
use App\Core\Interfaces\IAssistantGateway;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * IAssistantGateway over the ai-agent microservice (POST /api/v1/assistant/chat).
 *
 * ai-agent stays on the private network; this gateway is the only way the browser reaches it.
 */
final class AiAgentAssistantGateway implements IAssistantGateway
{
    public function ask(string $userToken, string $tenantHost, int $companyId, array $messages): array
    {
        $baseUrl = rtrim((string) config('services.ai_agent.url'), '/');
        $timeout = (int) config('services.ai_agent.assistant_timeout', 120);

        try {
            $response = Http::acceptJson()
                ->timeout($timeout)
                ->connectTimeout(5)
                ->post("{$baseUrl}/api/v1/assistant/chat", [
                    'context' => [
                        'token' => $userToken,
                        'tenant_host' => $tenantHost,
                        'company_id' => $companyId,
                    ],
                    'messages' => $messages,
                ]);
        } catch (ConnectionException $e) {
            Log::error('[AiAgentAssistantGateway] ai-agent unreachable', ['error' => $e->getMessage()]);

            throw AssistantUnavailableException::unreachable();
        }

        if ($response->failed()) {
            // Never log the request body: it carries the user's token.
            Log::error('[AiAgentAssistantGateway] ai-agent returned an error', ['status' => $response->status()]);
            $detail = $response->json('detail');

            throw AssistantUnavailableException::failed(is_string($detail) ? $detail : '');
        }

        return (array) $response->json();
    }
}
