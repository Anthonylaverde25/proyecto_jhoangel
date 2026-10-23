<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Models\Company;
use Illuminate\Support\Facades\DB;

/**
 * RestrictMcpTokenScope: an AI client token reads, only in its company, and never manages
 * connections; session tokens are untouched.
 */
class McpTokenScopeTest extends McpTestCase
{
    private string $mcpToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mcpToken = $this->issueManualConnection()['token'];
    }

    public function test_reads_in_the_company_of_the_connection(): void
    {
        $this->callWith($this->mcpToken, 'GET', '/dashboard/herd-summary')->assertOk();
    }

    public function test_cannot_write(): void
    {
        $before = DB::table('batches')->count();

        $this->callWith($this->mcpToken, 'POST', '/batches', ['name' => 'Lote IA'])
            ->assertForbidden()
            ->assertJsonPath('message', 'Una conexión de IA sólo puede consultar; no modifica datos.');

        $this->assertSame($before, DB::table('batches')->count());
    }

    public function test_cannot_read_another_company_even_if_the_user_belongs_to_it(): void
    {
        $other = Company::create(['name' => 'Estancia Vecina', 'cuit' => '30-71888888-8']);
        $this->linkUserToCompany($this->user, $other->id);

        $this->callWith($this->mcpToken, 'GET', '/dashboard/herd-summary', companyId: $other->id)
            ->assertForbidden()
            ->assertJsonPath('message', 'Esta conexión de IA pertenece a otra empresa.');
    }

    public function test_needs_the_company_header_even_on_routes_without_guard(): void
    {
        $this->callWith($this->mcpToken, 'GET', '/caravans', withCompany: false)->assertForbidden();
    }

    public function test_cannot_manage_connections_nor_reach_the_consent_endpoints(): void
    {
        $this->callWith($this->mcpToken, 'GET', '/mcp/connections')->assertForbidden();
        $this->callWith($this->mcpToken, 'GET', '/mcp/oauth/authorization-request')->assertForbidden();
    }

    public function test_session_tokens_keep_writing(): void
    {
        $this->callWith($this->sessionToken, 'POST', '/mcp/connections', ['name' => 'Gemini casa', 'ttl_days' => 30])
            ->assertCreated();
    }

    public function test_an_expired_token_is_rejected(): void
    {
        $this->travel(91)->days();

        $this->callWith($this->mcpToken, 'GET', '/dashboard/herd-summary')->assertUnauthorized();
    }

    public function test_session_endpoint_describes_the_connection(): void
    {
        $this->callWith($this->mcpToken, 'GET', '/mcp/session', withCompany: false)
            ->assertOk()
            ->assertJsonPath('company.id', $this->company->id)
            ->assertJsonPath('user.id', $this->user->id)
            ->assertJsonPath('abilities', ['mcp:read'])
            ->assertJsonPath('source', 'manual');
    }

    public function test_session_endpoint_refuses_session_tokens(): void
    {
        $this->callWith($this->sessionToken, 'GET', '/mcp/session')->assertForbidden();
    }
}
