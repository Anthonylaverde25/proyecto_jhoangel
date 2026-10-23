<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use Illuminate\Testing\TestResponse;
use Tests\Feature\Veterinary\VeterinaryTestCase;

/**
 * Shared fixture for AI client connections: a tenant, a company, a user and helpers that call
 * the API with a real bearer token (MCP tokens must be real Sanctum tokens, never actingAs).
 */
abstract class McpTestCase extends VeterinaryTestCase
{
    protected string $sessionToken;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'livestock.mcp.public_url' => 'https://{hostname}/mcp/',
            'livestock.mcp.issuer_url' => 'https://{hostname}',
            'livestock.mcp.consent_url' => 'https://{hostname}/oauth/consent',
            // A developer's tunnel (MCP_PUBLIC_HOSTNAME in .env) must not leak into the assertions.
            'livestock.mcp.public_hostname' => null,
        ]);

        $this->sessionToken = $this->user->createToken('session')->plainTextToken;
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function callWith(string $token, string $method, string $path, array $payload = [], ?int $companyId = null, bool $withCompany = true): TestResponse
    {
        // Guards keep the user of the previous request inside one test; each call must authenticate anew.
        $this->app['auth']->forgetGuards();
        // Default headers pile up across calls of one test; each call sends exactly its own.
        $this->flushHeaders();

        $headers = ['Authorization' => 'Bearer ' . $token];
        if ($withCompany) {
            $headers['X-Company-ID'] = (string) ($companyId ?? $this->company->id);
        }

        return $this->withHeaders($headers)->json($method, $this->url($path), $payload);
    }

    /** @return array{id: int, token: string} a manual MCP connection of the fixture user */
    protected function issueManualConnection(string $name = 'Claude oficina', int $ttlDays = 90): array
    {
        $response = $this->callWith($this->sessionToken, 'POST', '/mcp/connections', ['name' => $name, 'ttl_days' => $ttlDays])
            ->assertCreated();

        return ['id' => (int) $response->json('data.id'), 'token' => (string) $response->json('meta.plain_text_token')];
    }

    protected function oauthUrl(string $path): string
    {
        return 'http://' . $this->host . $path;
    }
}
