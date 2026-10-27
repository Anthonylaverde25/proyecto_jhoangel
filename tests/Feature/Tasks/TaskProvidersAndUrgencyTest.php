<?php

declare(strict_types=1);

namespace Tests\Feature\Tasks;

use App\Models\Company;
use App\Models\Provider;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Mcp\McpTestCase;

/**
 * Urgency, providers (many to many, same company) and the listing of tasks.
 */
class TaskProvidersAndUrgencyTest extends McpTestCase
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
    private function createTask(array $payload, ?string $token = null): \Illuminate\Testing\TestResponse
    {
        return $this->callWith($token ?? $this->sessionToken, 'POST', '/tasks', $payload);
    }

    public function test_a_task_is_not_urgent_unless_said_otherwise(): void
    {
        $this->createTask(['description' => 'Revisar alambrado'])
            ->assertCreated()
            ->assertJsonPath('data.urgency', 'not_urgent')
            ->assertJsonPath('data.providers', []);

        $this->createTask(['description' => 'Vacunar terneros', 'urgency' => 'very_urgent'])
            ->assertCreated()
            ->assertJsonPath('data.urgency', 'very_urgent');

        $this->createTask(['description' => 'Algo', 'urgency' => 'high'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('urgency');
    }

    public function test_links_the_providers_in_the_same_company(): void
    {
        $agro = $this->provider('Agro Sur', '30-71111111-1');
        $vet = $this->provider('Veterinaria Norte', '30-72222222-2');

        $this->createTask(['description' => 'Pedir cotización de alimento', 'provider_ids' => [$agro->id, $vet->id]])
            ->assertCreated()
            ->assertJsonCount(2, 'data.providers')
            ->assertJsonPath('data.providers.0.name', 'Agro Sur');

        $this->assertSame(2, DB::table('task_provider')->where('company_id', $this->company->id)->count());
    }

    public function test_a_provider_of_another_company_is_refused_and_nothing_is_stored(): void
    {
        $other = Company::create(['name' => 'Estancia Vecina', 'cuit' => '30-71888888-8']);
        $foreign = $this->provider('Proveedor Ajeno', '30-73333333-3', $other->id);

        $this->createTask(['description' => 'Tarea con proveedor ajeno', 'provider_ids' => [$foreign->id]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('provider_ids.0');

        $this->assertSame(0, DB::table('tasks')->count());
        $this->assertSame(0, DB::table('task_provider')->count());
    }

    public function test_repeated_providers_are_refused(): void
    {
        $agro = $this->provider('Agro Sur', '30-71111111-1');

        $this->createTask(['description' => 'Tarea repetida', 'provider_ids' => [$agro->id, $agro->id]])
            ->assertUnprocessable();
    }

    public function test_lists_the_most_urgent_first_and_filters(): void
    {
        $agro = $this->provider('Agro Sur', '30-71111111-1');
        $this->createTask(['description' => 'Limpiar galpón']);
        $this->createTask(['description' => 'Llamar al veterinario', 'urgency' => 'urgent']);
        $this->createTask(['description' => 'Pedir alimento a Agro Sur', 'urgency' => 'very_urgent', 'provider_ids' => [$agro->id]]);

        $this->callWith($this->sessionToken, 'GET', '/tasks')
            ->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.0.urgency', 'very_urgent')
            ->assertJsonPath('data.1.urgency', 'urgent')
            ->assertJsonPath('data.2.urgency', 'not_urgent');

        $this->callWith($this->sessionToken, 'GET', '/tasks?urgency=urgent')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.description', 'Llamar al veterinario');

        $this->callWith($this->sessionToken, 'GET', '/tasks?provider_id=' . $agro->id)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.providers.0.id', $agro->id);

        $this->callWith($this->sessionToken, 'GET', '/tasks?search=galp')
            ->assertJsonPath('meta.total', 1);

        $this->callWith($this->sessionToken, 'GET', '/tasks?per_page=2&page=2')
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonCount(1, 'data');
    }

    public function test_lists_only_the_tasks_of_the_active_company(): void
    {
        $other = Company::create(['name' => 'Estancia Vecina', 'cuit' => '30-71888888-8']);
        DB::table('tasks')->insert(['company_id' => $other->id, 'description' => 'Ajena', 'urgency' => 'urgent', 'created_at' => now(), 'updated_at' => now()]);
        $this->createTask(['description' => 'Propia']);

        $this->callWith($this->sessionToken, 'GET', '/tasks')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.description', 'Propia');
    }

    public function test_an_mcp_token_lists_and_creates_tasks_with_providers(): void
    {
        $agro = $this->provider('Agro Sur', '30-71111111-1');
        $token = $this->issueManualConnection()['token'];

        $this->createTask(['description' => 'Desde Claude', 'urgency' => 'urgent', 'provider_ids' => [$agro->id]], $token)
            ->assertCreated();

        $this->callWith($token, 'GET', '/tasks')->assertOk()->assertJsonPath('meta.total', 1);
        $this->callWith($token, 'GET', '/providers')->assertOk();
    }
}
