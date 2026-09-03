<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\Company;
use App\Models\Farm;
use App\Models\Provider;
use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ListBatchesWithRenspaTest extends TestCase
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
        
        // Update company with test RENSPA
        $this->company->update([
            'renspa' => 'COMPANY-RENSPA-123'
        ]);

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

    public function test_batches_list_returns_correct_computed_renspa(): void
    {
        // 1. Create Own Batch (farm_id = null)
        $ownBatch = Batch::create([
            'company_id' => $this->company->id,
            'name' => 'Lote Propio Computado',
            'farm_id' => null,
            'is_active' => true
        ]);

        // 2. Create Provider and External Farm with specific RENSPA
        $provider = Provider::create([
            'name' => 'Proveedor Externo 2',
            'cuit' => '30-11112222-4',
            'is_active' => true
        ]);

        $externalFarm = Farm::create([
            'name' => 'Granja Proveedor 2',
            'renspa' => 'PROVIDER-RENSPA-999',
            'provider_id' => $provider->id,
            'is_active' => true
        ]);

        // 3. Create External Batch (farm_id = externalFarm)
        $externalBatch = Batch::create([
            'company_id' => $this->company->id,
            'name' => 'Lote Externo Computado',
            'farm_id' => $externalFarm->id,
            'is_active' => true
        ]);

        // 4. Request all batches from API
        $response = $this->getJson('http://test.localhost/api/batches', [
            'X-Company-ID' => (string) $this->company->id,
        ]);

        // 5. Assert status code
        $response->assertStatus(200);

        $batches = $response->json();
        
        // Find both batches in response
        $ownBatchJson = collect($batches)->firstWhere('name', 'Lote Propio Computado');
        $externalBatchJson = collect($batches)->firstWhere('name', 'Lote Externo Computado');

        $this->assertNotNull($ownBatchJson);
        $this->assertNotNull($externalBatchJson);

        // Verify computed RENSPA values
        $this->assertEquals('COMPANY-RENSPA-123', $ownBatchJson['renspa']);
        $this->assertEquals('PROVIDER-RENSPA-999', $externalBatchJson['renspa']);
    }
}
