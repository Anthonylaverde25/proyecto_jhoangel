<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Models\Caravan;
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
            ->assertJsonPath('message', 'Una conexión de IA sólo puede consultar y registrar lo permitido; esta acción se hace desde el sistema.');

        $this->assertSame($before, DB::table('batches')->count());
    }

    public function test_may_record_a_weighing_only_through_the_ai_route(): void
    {
        $caravan = Caravan::create(['company_id' => $this->company->id, 'identification' => 'MCP-W-01', 'sex' => 'M', 'teeth' => 0]);
        $body = ['weight' => 300, 'weighing_date' => '2026-10-01'];

        $this->callWith($this->mcpToken, 'POST', "/caravans/{$caravan->id}/ai-weighing", $body)
            ->assertCreated()
            ->assertJsonPath('data.new_weight', 300);

        $this->callWith($this->mcpToken, 'POST', "/caravans/{$caravan->id}/weights", $body)->assertForbidden();
        $this->callWith($this->mcpToken, 'POST', '/caravans/bulk-weights', ['weights' => []])->assertForbidden();
        $this->assertSame(1, DB::table('caravan_weights')->where('caravan_id', $caravan->id)->count());
    }

    public function test_can_use_the_ai_consumption_routes_but_not_the_ones_of_the_screen(): void
    {
        // Reaches the use case (a validation error, not the gate), and never the screen's write.
        $this->callWith($this->mcpToken, 'POST', '/item-documents/ai-consumptions', [])->assertStatus(422);
        $this->callWith($this->mcpToken, 'GET', '/item-documents/ai-consumptions/preview')->assertStatus(422);

        $this->callWith($this->mcpToken, 'POST', '/item-documents/consumptions', [])->assertForbidden();
        $this->callWith($this->mcpToken, 'POST', '/item-documents/transfers', [])->assertForbidden();
        $this->callWith($this->mcpToken, 'POST', '/item-documents/1/reversal', [])->assertForbidden();
    }

    public function test_cannot_weigh_with_the_header_of_another_company(): void
    {
        $caravan = Caravan::create(['company_id' => $this->company->id, 'identification' => 'MCP-W-02', 'sex' => 'M', 'teeth' => 0]);
        $other = Company::create(['name' => 'Estancia Lejana', 'cuit' => '30-71777777-7']);
        $this->linkUserToCompany($this->user, $other->id);

        $this->callWith($this->mcpToken, 'POST', "/caravans/{$caravan->id}/ai-weighing", ['weight' => 300, 'weighing_date' => '2026-10-01'], companyId: $other->id)
            ->assertForbidden();

        $this->assertSame(0, DB::table('caravan_weights')->where('caravan_id', $caravan->id)->count());
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
