<?php

declare(strict_types=1);

namespace Tests\Feature\Tasks;

use App\Models\Company;
use App\Models\Provider;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Mcp\McpTestCase;

/**
 * GET and PATCH /api/tasks/{id}: detail and partial edit, inside the active company.
 */
class ShowAndUpdateTaskTest extends McpTestCase
{
    private function provider(string $name, string $cuit, ?int $companyId = null): Provider
    {
        return Provider::withoutGlobalScopes()->create([
            'company_id' => $companyId ?? $this->company->id,
            'name' => $name,
            'cuit' => $cuit,
            'is_active' => true,
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function task(array $payload = []): int
    {
        return (int) $this->callWith($this->sessionToken, 'POST', '/tasks', ['description' => 'Pedir alimento', ...$payload])
            ->assertCreated()
            ->json('data.id');
    }

    /** @param array<string, mixed> $payload */
    private function edit(int $id, array $payload, ?string $token = null): TestResponse
    {
        return $this->callWith($token ?? $this->sessionToken, 'PATCH', '/tasks/' . $id, $payload);
    }

    public function test_shows_a_task_with_its_providers(): void
    {
        $agro = $this->provider('Agro Sur', '30-71111111-1');
        $id = $this->task(['urgency' => 'urgent', 'provider_ids' => [$agro->id]]);

        $this->callWith($this->sessionToken, 'GET', '/tasks/' . $id)
            ->assertOk()
            ->assertJsonPath('data.urgency', 'urgent')
            ->assertJsonPath('data.providers.0.name', 'Agro Sur');

        $this->callWith($this->sessionToken, 'GET', '/tasks/999999')->assertNotFound();
    }

    public function test_a_task_of_another_company_is_not_found(): void
    {
        $other = Company::create(['name' => 'Estancia Vecina', 'cuit' => '30-71888888-8']);
        $foreignId = DB::table('tasks')->insertGetId(['company_id' => $other->id, 'description' => 'Ajena', 'urgency' => 'urgent', 'created_at' => now(), 'updated_at' => now()]);

        $this->callWith($this->sessionToken, 'GET', '/tasks/' . $foreignId)->assertNotFound();
        $this->edit($foreignId, ['description' => 'Robada'])->assertNotFound();
        $this->assertDatabaseHas('tasks', ['id' => $foreignId, 'description' => 'Ajena']);
    }

    public function test_edits_only_the_fields_sent(): void
    {
        $agro = $this->provider('Agro Sur', '30-71111111-1');
        $id = $this->task(['provider_ids' => [$agro->id]]);

        $this->edit($id, ['urgency' => 'very_urgent'])
            ->assertOk()
            ->assertJsonPath('data.description', 'Pedir alimento')
            ->assertJsonPath('data.urgency', 'very_urgent')
            ->assertJsonPath('data.providers.0.id', $agro->id);

        $this->edit($id, ['description' => '  Pedir alimento balanceado  '])
            ->assertOk()
            ->assertJsonPath('data.description', 'Pedir alimento balanceado')
            ->assertJsonPath('data.urgency', 'very_urgent');
    }

    public function test_replaces_and_clears_the_providers(): void
    {
        $agro = $this->provider('Agro Sur', '30-71111111-1');
        $vet = $this->provider('Veterinaria Norte', '30-72222222-2');
        $id = $this->task(['provider_ids' => [$agro->id]]);

        $this->edit($id, ['provider_ids' => [$vet->id]])
            ->assertOk()
            ->assertJsonCount(1, 'data.providers')
            ->assertJsonPath('data.providers.0.id', $vet->id);
        $this->assertSame($this->company->id, (int) DB::table('task_provider')->where('task_id', $id)->value('company_id'));

        $this->edit($id, ['provider_ids' => []])
            ->assertOk()
            ->assertJsonPath('data.providers', []);
    }

    public function test_invalid_edits_change_nothing(): void
    {
        $other = Company::create(['name' => 'Estancia Vecina', 'cuit' => '30-71888888-8']);
        $foreign = $this->provider('Proveedor Ajeno', '30-73333333-3', $other->id);
        $id = $this->task();

        $this->edit($id, [])->assertUnprocessable()->assertJsonValidationErrors('task');
        $this->edit($id, ['urgency' => 'high'])->assertUnprocessable();
        $this->edit($id, ['description' => ''])->assertUnprocessable();
        $this->edit($id, ['description' => 'Cambio', 'provider_ids' => [$foreign->id]])->assertUnprocessable();

        $this->assertDatabaseHas('tasks', ['id' => $id, 'description' => 'Pedir alimento', 'urgency' => 'not_urgent']);
    }

    public function test_an_mcp_token_can_read_and_edit_tasks_but_not_other_records(): void
    {
        $agro = $this->provider('Agro Sur', '30-71111111-1');
        $id = $this->task();
        $token = $this->issueManualConnection()['token'];

        $this->callWith($token, 'GET', '/tasks/' . $id)->assertOk();
        $this->edit($id, ['urgency' => 'urgent'], $token)->assertOk()->assertJsonPath('data.urgency', 'urgent');
        $this->callWith($token, 'GET', '/providers/' . $agro->id)->assertOk();

        $this->callWith($token, 'PATCH', '/providers/' . $agro->id . '/roles', ['supplies_items' => true])->assertForbidden();
        $this->callWith($token, 'PUT', '/providers/' . $agro->id, ['name' => 'Otro'])->assertForbidden();
    }
}
