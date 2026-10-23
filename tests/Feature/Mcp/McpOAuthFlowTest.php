<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Models\McpConnection;

/**
 * The OAuth 2.1 flow an AI client (claude.ai, Gemini) runs to connect: discovery, dynamic
 * registration, authorization with PKCE through the SPA consent screen, token exchange and
 * refresh rotation.
 */
class McpOAuthFlowTest extends McpTestCase
{
    private const REDIRECT_URI = 'https://claude.ai/api/mcp/auth_callback';

    private string $verifier;
    private string $challenge;

    protected function setUp(): void
    {
        parent::setUp();

        $this->verifier = str_repeat('v', 20) . bin2hex(random_bytes(16));
        $this->challenge = rtrim(strtr(base64_encode(hash('sha256', $this->verifier, true)), '+/', '-_'), '=');
    }

    public function test_publishes_the_authorization_server_metadata(): void
    {
        $this->getJson($this->oauthUrl('/.well-known/oauth-authorization-server'))
            ->assertOk()
            ->assertJsonPath('issuer', "https://{$this->host}")
            ->assertJsonPath('token_endpoint', "https://{$this->host}/oauth/token")
            ->assertJsonPath('registration_endpoint', "https://{$this->host}/oauth/register")
            ->assertJsonPath('code_challenge_methods_supported', ['S256']);
    }

    public function test_registers_a_public_client(): void
    {
        $this->postJson($this->oauthUrl('/oauth/register'), [
            'client_name' => 'Claude',
            'redirect_uris' => [self::REDIRECT_URI],
            'token_endpoint_auth_method' => 'client_secret_post',
        ])
            ->assertCreated()
            ->assertJsonPath('client_name', 'Claude')
            ->assertJsonPath('token_endpoint_auth_method', 'none');
    }

    public function test_rejects_plain_http_redirects_outside_loopback(): void
    {
        $this->postJson($this->oauthUrl('/oauth/register'), ['redirect_uris' => ['http://evil.example/cb']])
            ->assertStatus(400)
            ->assertJsonPath('error', 'invalid_redirect_uri');

        $this->postJson($this->oauthUrl('/oauth/register'), ['redirect_uris' => ['http://127.0.0.1:7777/oauth/callback']])
            ->assertCreated();
    }

    public function test_authorize_sends_the_browser_to_the_consent_screen(): void
    {
        $clientId = $this->registerClient();

        $response = $this->get($this->oauthUrl('/oauth/authorize?' . http_build_query($this->authorizationQuery($clientId))));

        $response->assertRedirect();
        $this->assertStringStartsWith("https://{$this->host}/oauth/consent?", (string) $response->headers->get('Location'));
    }

    public function test_authorize_never_redirects_to_an_unregistered_uri(): void
    {
        $clientId = $this->registerClient();
        $query = $this->authorizationQuery($clientId, ['redirect_uri' => 'https://evil.example/cb']);

        $this->get($this->oauthUrl('/oauth/authorize?' . http_build_query($query)))
            ->assertStatus(400)
            ->assertJsonPath('error', 'invalid_redirect_uri');
    }

    public function test_full_flow_gives_a_read_only_token_pinned_to_the_chosen_company(): void
    {
        $clientId = $this->registerClient();
        $tokens = $this->exchange($clientId, $this->approve($clientId))->assertOk()->json();

        $this->assertSame('Bearer', $tokens['token_type']);
        $this->assertSame('mcp:read', $tokens['scope']);

        $this->callWith($tokens['access_token'], 'GET', '/dashboard/herd-summary')->assertOk();
        $this->callWith($tokens['access_token'], 'POST', '/batches', ['name' => 'X'])->assertForbidden();

        $connection = McpConnection::query()->where('source', 'oauth')->firstOrFail();
        $this->assertSame('Claude', $connection->name);
        $this->assertSame($this->company->id, (int) $connection->company_id);
    }

    public function test_denying_returns_access_denied_to_the_client(): void
    {
        $clientId = $this->registerClient();

        $redirect = $this->callWith($this->sessionToken, 'POST', '/mcp/oauth/authorize', [
            ...$this->authorizationQuery($clientId),
            'decision' => 'deny',
        ])->assertOk()->json('redirect_url');

        $this->assertStringContainsString('error=access_denied', $redirect);
        $this->assertStringContainsString('state=xyz', $redirect);
    }

    public function test_a_wrong_code_verifier_is_rejected(): void
    {
        $clientId = $this->registerClient();
        $code = $this->approve($clientId);
        $this->verifier = str_repeat('w', 43);

        $this->exchange($clientId, $code)->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
    }

    public function test_a_replayed_code_revokes_the_connection_it_produced(): void
    {
        $clientId = $this->registerClient();
        $code = $this->approve($clientId);
        $accessToken = $this->exchange($clientId, $code)->assertOk()->json('access_token');

        $this->exchange($clientId, $code)->assertStatus(400)->assertJsonPath('error', 'invalid_grant');

        $this->callWith($accessToken, 'GET', '/dashboard/herd-summary')->assertUnauthorized();
    }

    public function test_refresh_rotates_both_tokens(): void
    {
        $clientId = $this->registerClient();
        $first = $this->exchange($clientId, $this->approve($clientId))->json();

        $second = $this->refresh($clientId, $first['refresh_token'])->assertOk()->json();

        $this->callWith($first['access_token'], 'GET', '/dashboard/herd-summary')->assertUnauthorized();
        $this->callWith($second['access_token'], 'GET', '/dashboard/herd-summary')->assertOk();
        $this->refresh($clientId, $first['refresh_token'])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
    }

    public function test_approving_the_same_client_again_replaces_the_previous_connection(): void
    {
        $clientId = $this->registerClient();
        $old = $this->exchange($clientId, $this->approve($clientId))->json('access_token');
        $new = $this->exchange($clientId, $this->approve($clientId))->json('access_token');

        $this->callWith($old, 'GET', '/dashboard/herd-summary')->assertUnauthorized();
        $this->callWith($new, 'GET', '/dashboard/herd-summary')->assertOk();
        $this->assertSame(1, McpConnection::query()->whereNull('revoked_at')->count());
    }

    public function test_consent_through_another_domain_of_the_tenant_keeps_the_public_issuer(): void
    {
        // The SPA calls its API on one domain (localhost in dev); the AI client knows the public one.
        $spaHost = 'spa-' . $this->host;
        $this->tenant->domains()->create(['domain' => $spaHost]);
        $clientId = $this->registerClient();

        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $redirect = (string) $this->withHeaders(['Authorization' => 'Bearer ' . $this->sessionToken, 'X-Company-ID' => (string) $this->company->id])
            ->postJson("http://{$spaHost}/api/mcp/oauth/authorize", [...$this->authorizationQuery($clientId), 'decision' => 'approve'])
            ->assertOk()
            ->json('redirect_url');

        parse_str((string) parse_url($redirect, PHP_URL_QUERY), $params);
        $this->assertSame("https://{$this->host}", $params['iss']);
        $this->exchange($clientId, (string) $params['code'])->assertOk();
    }

    public function test_gemini_style_request_without_resource_and_with_a_long_state(): void
    {
        // As Gemini sends it: no `resource`, a signed state over 1 KB, consent through another tenant host.
        $spaHost = 'spa-' . $this->host;
        $this->tenant->domains()->create(['domain' => $spaHost]);
        $clientId = $this->registerClient();
        $state = str_repeat('s', 1091);
        $query = $this->authorizationQuery($clientId, ['state' => $state, 'scope' => 'mcp:read openid']);
        unset($query['resource']);

        $consent = (string) $this->get($this->oauthUrl('/oauth/authorize?' . http_build_query($query)))->headers->get('Location');
        parse_str((string) parse_url($consent, PHP_URL_QUERY), $forwarded);
        $this->assertSame($this->host, $forwarded['mcp_host']);

        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $redirect = (string) $this->withHeaders(['Authorization' => 'Bearer ' . $this->sessionToken, 'X-Company-ID' => (string) $this->company->id])
            ->postJson("http://{$spaHost}/api/mcp/oauth/authorize", [...$forwarded, 'decision' => 'approve'])
            ->assertOk()
            ->json('redirect_url');

        parse_str((string) parse_url($redirect, PHP_URL_QUERY), $params);
        $this->assertSame($state, $params['state']);
        $this->assertSame("https://{$this->host}", $params['iss']);
        $this->exchange($clientId, (string) $params['code'])->assertOk()->assertJsonPath('scope', 'mcp:read');
    }

    public function test_a_foreign_mcp_host_is_ignored(): void
    {
        $clientId = $this->registerClient();
        $query = [...$this->authorizationQuery($clientId), 'mcp_host' => 'otra-estancia.example', 'decision' => 'approve'];
        unset($query['resource']);

        $redirect = (string) $this->callWith($this->sessionToken, 'POST', '/mcp/oauth/authorize', $query)->assertOk()->json('redirect_url');

        parse_str((string) parse_url($redirect, PHP_URL_QUERY), $params);
        $this->assertSame("https://{$this->host}", $params['iss']);
    }

    public function test_a_resource_on_a_foreign_domain_is_refused(): void
    {
        $clientId = $this->registerClient();

        $this->callWith($this->sessionToken, 'POST', '/mcp/oauth/authorize', [
            ...$this->authorizationQuery($clientId, ['resource' => 'https://otra-estancia.example/mcp']),
            'decision' => 'approve',
        ])->assertStatus(400)->assertJsonPath('error', 'invalid_request');
    }

    private function registerClient(): string
    {
        return (string) $this->postJson($this->oauthUrl('/oauth/register'), [
            'client_name' => 'Claude',
            'redirect_uris' => [self::REDIRECT_URI],
        ])->json('client_id');
    }

    /**
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    private function authorizationQuery(string $clientId, array $overrides = []): array
    {
        return [
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => self::REDIRECT_URI,
            'code_challenge' => $this->challenge,
            'code_challenge_method' => 'S256',
            'scope' => 'mcp:read',
            'state' => 'xyz',
            'resource' => "https://{$this->host}/mcp/",
            ...$overrides,
        ];
    }

    private function approve(string $clientId): string
    {
        $redirect = (string) $this->callWith($this->sessionToken, 'POST', '/mcp/oauth/authorize', [
            ...$this->authorizationQuery($clientId),
            'decision' => 'approve',
        ])->assertOk()->json('redirect_url');

        $this->assertStringStartsWith(self::REDIRECT_URI . '?', $redirect);
        parse_str((string) parse_url($redirect, PHP_URL_QUERY), $params);
        $this->assertSame('xyz', $params['state']);
        $this->assertSame("https://{$this->host}", $params['iss']);

        return (string) $params['code'];
    }

    private function exchange(string $clientId, string $code): \Illuminate\Testing\TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return $this->post($this->oauthUrl('/oauth/token'), [
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'code' => $code,
            'redirect_uri' => self::REDIRECT_URI,
            'code_verifier' => $this->verifier,
            'resource' => "https://{$this->host}/mcp/",
        ]);
    }

    private function refresh(string $clientId, string $refreshToken): \Illuminate\Testing\TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return $this->post($this->oauthUrl('/oauth/token'), [
            'grant_type' => 'refresh_token',
            'client_id' => $clientId,
            'refresh_token' => $refreshToken,
        ]);
    }
}
