<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\Caravan;
use App\Models\Company;
use App\Models\Farm;
use App\Models\Provider;
use App\Models\Tenant;
use App\Models\User;
use App\Core\Enums\AnimalSex;
use App\Core\Enums\AnimalCategory;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ExternalBatchRestrictedInBusinessFlowTest extends TestCase
{
    private Tenant $tenant;
    private Company $company;
    private User $user;

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

        $this->user = User::first() ?? User::create([
            'company_id' => $this->company->id,
            'name'       => 'Test User',
            'email'      => 'test@example.com',
            'password'   => bcrypt('password'),
        ]);

        Sanctum::actingAs($this->user, ['*']);
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

    public function test_cannot_create_service_order_with_animals_in_external_batches(): void
    {
        $provider = Provider::create([
            'name' => 'Cabaña Externa Vendedora',
            'cuit' => '30-11223344-5',
            'is_active' => true
        ]);

        $externalFarm = Farm::create([
            'name' => 'Finca de Proveedor',
            'renspa' => '01.001.1.11111/00',
            'provider_id' => $provider->id,
            'is_active' => true
        ]);

        $externalBatch = Batch::create([
            'company_id' => $this->company->id,
            'name' => 'Lote Externo 1',
            'farm_id' => $externalFarm->id,
            'is_active' => true
        ]);

        $bull = Caravan::create([
            'company_id' => $this->company->id,
            'batch_id' => $externalBatch->id,
            'identification' => '9001',
            'teeth' => 4,
            'sex' => AnimalSex::MALE,
            'renspa' => '01.001.1.11111/00'
        ]);

        $cow = Caravan::create([
            'company_id' => $this->company->id,
            'batch_id' => $externalBatch->id,
            'identification' => '9002',
            'teeth' => 4,
            'sex' => AnimalSex::FEMALE,
            'renspa' => '01.001.1.11111/00'
        ]);

        $response = $this->postJson('http://test.localhost/api/service-orders', [
            'batch_id' => $externalBatch->id,
            'code' => 'SO-2026-TEST',
            'planned_start_date' => '2026-09-01',
            'service_type' => 'single',
            'is_controlled_service' => false,
            'male_caravan_ids' => [$bull->id],
            'female_caravan_ids' => [$cow->id]
        ], [
            'X-Company-ID' => (string) $this->company->id,
        ]);

        $response->assertStatus(422);
    }
}
