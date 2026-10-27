<?php

declare(strict_types=1);

namespace Tests\Feature\Tasks;

use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Mcp\McpTestCase;

/**
 * POST /api/tasks: a task of the active company, recorded with a session token or an MCP token.
 */
class CreateTaskTest extends McpTestCase
{
    public function test_records_a_task_in_the_active_company(): void
    {
        $this->callWith($this->sessionToken, 'POST', '/tasks', ['description' => '  Revisar el bebedero del lote 4  '])
            ->assertCreated()
            ->assertJsonPath('data.description', 'Revisar el bebedero del lote 4');

        $this->assertDatabaseHas('tasks', [
            'company_id' => $this->company->id,
            'description' => 'Revisar el bebedero del lote 4',
        ]);
    }

    public function test_the_company_comes_from_the_context_not_the_body(): void
    {
        $other = Company::create(['name' => 'Estancia Vecina', 'cuit' => '30-71888888-8']);

        $this->callWith($this->sessionToken, 'POST', '/tasks', ['description' => 'Vacunar terneros', 'company_id' => $other->id])
            ->assertCreated();

        $this->assertSame(0, DB::table('tasks')->where('company_id', $other->id)->count());
        $this->assertSame(1, DB::table('tasks')->where('company_id', $this->company->id)->count());
    }

    public function test_the_text_is_required(): void
    {
        $this->callWith($this->sessionToken, 'POST', '/tasks', ['description' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('description');

        $this->callWith($this->sessionToken, 'POST', '/tasks', ['description' => str_repeat('a', 2001)])
            ->assertUnprocessable();
    }

    public function test_an_mcp_token_can_record_a_task(): void
    {
        $token = $this->issueManualConnection()['token'];

        $this->callWith($token, 'POST', '/tasks', ['description' => 'Llamar al veterinario'])
            ->assertCreated();

        $this->assertDatabaseHas('tasks', ['company_id' => $this->company->id, 'description' => 'Llamar al veterinario']);
    }

    public function test_an_mcp_token_still_cannot_perform_other_writes(): void
    {
        $token = $this->issueManualConnection()['token'];

        $this->callWith($token, 'POST', '/batches', ['name' => 'Lote IA'])->assertForbidden();
        $this->callWith($token, 'POST', '/mcp/connections', ['name' => 'Otra IA', 'ttl_days' => 30])->assertForbidden();
    }

    public function test_an_mcp_token_records_tasks_only_in_its_company(): void
    {
        $other = Company::create(['name' => 'Estancia Vecina', 'cuit' => '30-71888888-8']);
        $this->linkUserToCompany($this->user, $other->id);
        $token = $this->issueManualConnection()['token'];

        $this->callWith($token, 'POST', '/tasks', ['description' => 'Tarea ajena'], companyId: $other->id)
            ->assertForbidden();

        $this->assertSame(0, DB::table('tasks')->count());
    }

    public function test_a_revoked_mcp_connection_cannot_record_tasks(): void
    {
        $connection = $this->issueManualConnection();
        $this->callWith($this->sessionToken, 'DELETE', '/mcp/connections/' . $connection['id'])->assertNoContent();

        $this->callWith($connection['token'], 'POST', '/tasks', ['description' => 'Tarea tardía'])
            ->assertUnauthorized();
    }
}
