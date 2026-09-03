<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Enums\AnimalCategory;
use App\Core\Enums\AnimalSex;
use App\Models\Batch;
use App\Models\Caravan;
use App\Models\Company;
use App\Models\Farm;
use App\Models\Provider;
use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ListOwnCaravansOnlyTest extends TestCase
{
    private Tenant $tenant;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate');
        $this->tenant = Tenant::create(['id' => 'test-tenant-' . uniqid()]);
        $this->tenant->domains()->create(['domain' => 'test.localhost']);
        tenancy()->initialize($this->tenant);

        $this->company = Company::first();
        $this->assertNotNull($this->company);

        $companyContext = new \App\Core\Contexts\CompanyContext();
        $companyContext->setCompanyId($this->company->id);
        $this->app->instance(\App\Core\Interfaces\ICompanyContext::class, $companyContext);
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        $this->tenant->delete();
        Artisan::call('migrate:rollback');

        parent::tearDown();
    }

    public function test_can_list_own_caravans_assigned_to_own_batches(): void
    {
        // 1. Create an Own Batch (farm_id = null)
        $ownBatch = Batch::create([
            'company_id' => $this->company->id,
            'name' => 'Lote Propio Principal',
            'farm_id' => null,
            'is_active' => true
        ]);

        // 2. Create an Own Caravan
        $ownCaravan = Caravan::create([
            'company_id' => $this->company->id,
            'batch_id' => $ownBatch->id,
            'renspa' => 'NO_DEFINIDO',
            'identification' => 'OWN-001',
            'teeth' => 2,
            'sex' => AnimalSex::FEMALE,
            'entry_weight' => 380.0
        ]);

        // 3. Create a Provider and External Farm
        $provider = Provider::create([
            'name' => 'Proveedor Externo',
            'cuit' => '30-11112222-3',
            'is_active' => true
        ]);

        $externalFarm = Farm::create([
            'name' => 'Granja Proveedor',
            'renspa' => '01.002.3.00456/00',
            'provider_id' => $provider->id,
            'is_active' => true
        ]);

        // 4. Create an External Batch (farm_id = externalFarm)
        $externalBatch = Batch::create([
            'company_id' => $this->company->id,
            'name' => 'Lote Externo Compra',
            'farm_id' => $externalFarm->id,
            'is_active' => true
        ]);

        // 5. Create an External Caravan
        $externalCaravan = Caravan::create([
            'company_id' => $this->company->id,
            'batch_id' => $externalBatch->id,
            'provider_id' => $provider->id,
            'renspa' => '01.002.3.00456/00',
            'identification' => 'EXT-999',
            'teeth' => 2,
            'sex' => AnimalSex::FEMALE,
            'entry_weight' => 390.0
        ]);

        // 6. Request to get caravans with scope=own
        $response = $this->getJson('http://test.localhost/api/caravans?scope=own', [
            'X-Company-ID' => (string) $this->company->id,
        ]);

        // 7. Assert success and verify filtering
        $response->assertStatus(200);
        
        $caravans = $response->json();
        
        // Assert we returned the own caravan and excluded the external caravan
        $identifications = collect($caravans)->pluck('identification')->toArray();
        
        $this->assertContains('OWN-001', $identifications);
        $this->assertNotContains('EXT-999', $identifications);
    }
}
