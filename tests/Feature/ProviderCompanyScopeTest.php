<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Farm;
use App\Models\Provider;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Veterinary\VeterinaryTestCase;

/**
 * Providers and their origin farms belong to one company (D13, D21): each company keeps its own
 * portfolio, the same CUIT may exist once per company, and the database refuses to cross them.
 */
class ProviderCompanyScopeTest extends VeterinaryTestCase
{
    private const CUIT = '30555555551';

    private Company $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->other = Company::where('id', '!=', $this->company->id)->first()
            ?? Company::create(['name' => 'Campo Vecino Test', 'cuit' => '30-71888888-8']);
        $this->linkUserToCompany($this->user, (int) $this->other->id);
    }

    public function test_the_same_cuit_is_one_provider_per_company(): void
    {
        $this->apiAs('POST', '/providers', ['name' => 'Agroveterinaria El Tala', 'cuit' => self::CUIT])->assertStatus(201);

        $repeated = $this->apiAs('POST', '/providers', ['name' => 'El Tala (otra vez)', 'cuit' => self::CUIT]);
        $repeated->assertStatus(422)->assertJsonValidationErrors('cuit');

        $first = $this->company;
        $this->company = $this->other;
        $this->apiAs('POST', '/providers', ['name' => 'Agroveterinaria El Tala', 'cuit' => self::CUIT])->assertStatus(201);
        $this->company = $first;

        $this->assertEqualsCanonicalizing(
            [(int) $first->id, (int) $this->other->id],
            Provider::withoutGlobalScopes()->where('cuit', self::CUIT)->pluck('company_id')->all()
        );
    }

    public function test_each_company_lists_only_its_own_providers(): void
    {
        Provider::withoutGlobalScopes()->create([
            'company_id' => $this->other->id, 'name' => 'Solo del vecino', 'cuit' => '30666666662',
        ]);

        $names = array_column($this->apiAs('GET', '/providers')->assertOk()->json(), 'name');

        $this->assertNotContains('Solo del vecino', $names);
    }

    public function test_the_list_filters_by_role(): void
    {
        $this->apiAs('POST', '/providers', [
            'name' => 'YPF Agro Test', 'cuit' => '30777777773', 'supplies_livestock' => false, 'supplies_items' => true,
        ])->assertStatus(201)->assertJsonPath('supplies_items', true);

        $livestock = array_column($this->apiAs('GET', '/providers?supplies=livestock')->json(), 'name');
        $items = array_column($this->apiAs('GET', '/providers?supplies=items')->json(), 'name');

        $this->assertNotContains('YPF Agro Test', $livestock);
        $this->assertContains('YPF Agro Test', $items);
    }

    public function test_a_provider_needs_at_least_one_role(): void
    {
        $response = $this->apiAs('POST', '/providers', [
            'name' => 'Sin rol', 'cuit' => '30888888884', 'supplies_livestock' => false, 'supplies_items' => false,
        ]);

        $response->assertStatus(422);
        $this->assertFalse(Provider::withoutGlobalScopes()->where('cuit', '30888888884')->exists());
    }

    public function test_a_farm_cannot_hang_from_a_provider_of_another_company(): void
    {
        $foreign = Provider::withoutGlobalScopes()->create([
            'company_id' => $this->other->id, 'name' => 'Proveedor del vecino', 'cuit' => '30999999995',
        ]);

        $this->apiAs('POST', '/farms', ['name' => 'Campo ajeno', 'provider_id' => $foreign->id])
            ->assertStatus(422)->assertJsonValidationErrors('provider_id');

        // The composite foreign key refuses it even when validation is bypassed.
        $this->expectException(QueryException::class);
        DB::table('farms')->insert([
            'company_id' => $this->company->id, 'provider_id' => $foreign->id, 'name' => 'Campo ajeno',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_several_farms_of_a_company_may_lack_a_renspa(): void
    {
        $provider = Provider::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'name' => 'Consignataria Test', 'cuit' => '30-12121212-1',
        ]);

        $this->apiAs('POST', '/farms', ['name' => 'Campo sin RENSPA 1', 'provider_id' => $provider->id])->assertStatus(201);
        $this->apiAs('POST', '/farms', ['name' => 'Campo sin RENSPA 2', 'provider_id' => $provider->id])->assertStatus(201);

        $this->assertSame(2, Farm::withoutGlobalScopes()->where('provider_id', $provider->id)->whereNull('renspa')->count());
    }
}
