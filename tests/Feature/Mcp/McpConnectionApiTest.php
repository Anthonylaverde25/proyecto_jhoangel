<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Models\McpConnection;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Connections created by hand from /settings: the token the user pastes into an AI client.
 */
class McpConnectionApiTest extends McpTestCase
{
    public function test_issues_a_sanctum_token_with_the_single_mcp_ability(): void
    {
        $response = $this->callWith($this->sessionToken, 'POST', '/mcp/connections', ['name' => 'Claude oficina', 'ttl_days' => 30])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Claude oficina')
            ->assertJsonPath('data.source', 'manual')
            ->assertJsonPath('meta.server_url', "https://{$this->host}/mcp/");

        $this->assertNotEmpty($response->json('meta.plain_text_token'));

        $connection = McpConnection::query()->findOrFail($response->json('data.id'));
        $this->assertSame($this->company->id, (int) $connection->company_id);
        $this->assertSame($this->user->id, (int) $connection->user_id);

        $abilities = DB::table('personal_access_tokens')->where('id', $connection->personal_access_token_id)->value('abilities');
        $this->assertSame(['mcp:read'], json_decode((string) $abilities, true));
    }

    public function test_rejects_a_validity_out_of_range(): void
    {
        $this->callWith($this->sessionToken, 'POST', '/mcp/connections', ['name' => 'Claude', 'ttl_days' => 400])
            ->assertStatus(422)
            ->assertJsonPath('errors.ttl_days.0', 'La vigencia debe estar entre 1 y 180 días.');
    }

    public function test_limits_the_active_connections_per_user(): void
    {
        config(['livestock.mcp.max_active_per_user' => 2]);
        $this->issueManualConnection('Uno');
        $this->issueManualConnection('Dos');

        $this->callWith($this->sessionToken, 'POST', '/mcp/connections', ['name' => 'Tres', 'ttl_days' => 30])
            ->assertStatus(422);
    }

    public function test_lists_only_the_users_own_connections(): void
    {
        $mine = $this->issueManualConnection('Mía');

        $other = User::create(['name' => 'Otro', 'email' => 'otro@prueba.com', 'password' => bcrypt('secret123')]);
        $this->linkUserToCompany($other, $this->company->id);
        $otherSession = $other->createToken('session')->plainTextToken;
        $this->callWith($otherSession, 'POST', '/mcp/connections', ['name' => 'Ajena', 'ttl_days' => 30])->assertCreated();

        $this->callWith($this->sessionToken, 'GET', '/mcp/connections')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine['id'])
            ->assertJsonPath('meta.server_url', "https://{$this->host}/mcp/");
    }

    public function test_a_revoked_connection_stops_on_its_next_read(): void
    {
        $connection = $this->issueManualConnection();
        $this->callWith($connection['token'], 'GET', '/dashboard/herd-summary')->assertOk();

        $this->callWith($this->sessionToken, 'DELETE', "/mcp/connections/{$connection['id']}")->assertNoContent();

        $this->callWith($connection['token'], 'GET', '/dashboard/herd-summary')->assertUnauthorized();
        $this->assertNotNull(McpConnection::query()->findOrFail($connection['id'])->revoked_at);
    }

    public function test_cannot_revoke_someone_elses_connection(): void
    {
        $connection = $this->issueManualConnection();

        $other = User::create(['name' => 'Otro', 'email' => 'otro2@prueba.com', 'password' => bcrypt('secret123')]);
        $this->linkUserToCompany($other, $this->company->id);

        $this->callWith($other->createToken('session')->plainTextToken, 'DELETE', "/mcp/connections/{$connection['id']}")
            ->assertNotFound();
        $this->callWith($connection['token'], 'GET', '/dashboard/herd-summary')->assertOk();
    }
}
