<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Tenant;
use App\Models\Caravan;
use App\Models\CaravanGestation;
use App\Models\ServiceOrder;
use App\Models\Batch;
use App\Models\Farm;
use App\Models\Provider;
use App\Models\User;
use App\Core\Enums\AnimalSex;
use App\Core\Enums\GestationStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ImportOCRGestationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Company $company;
    private User $user;
    private ServiceOrder $serviceOrder;
    private Caravan $femaleCaravan;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Manually migrate Landlord database
        Artisan::call('migrate');

        // 2. Create Tenant
        $this->tenant = Tenant::create(['id' => 'test-tenant-' . uniqid()]);
        $this->tenant->domains()->create(['domain' => 'test.localhost']);

        // 3. Initialize Tenancy
        tenancy()->initialize($this->tenant);

        // 4. Create Company
        $this->company = Company::create([
            'name' => 'Test ranch for Gestation OCR',
            'renspa' => '999999999999',
            'location' => 'Patagonia',
            'is_active' => true,
        ]);

        // Mock CompanyContext
        $companyContext = new \App\Core\Contexts\CompanyContext();
        $companyContext->setCompanyId($this->company->id);
        $this->app->instance(\App\Core\Interfaces\ICompanyContext::class, $companyContext);

        // 5. Create User
        $this->user = User::create([
            'name'     => 'Operator',
            'email'    => 'operator@ranch.com',
            'password' => bcrypt('password'),
        ]);

        // 6. Create Provider, Farm, Batch, Service Order
        $provider = Provider::firstOrCreate(
            ['cuit' => '30-98765432-1'],
            ['name' => 'Gestation Provider', 'is_active' => true]
        );
        $farm = Farm::firstOrCreate(
            ['renspa' => '01.02.0.00001/01'],
            ['name' => 'Section A', 'provider_id' => $provider->id, 'is_active' => true]
        );
        $batch = Batch::firstOrCreate(
            ['company_id' => $this->company->id, 'name' => 'Lote Recría - 3'],
            ['farm_id' => $farm->id, 'is_active' => true]
        );

        $this->serviceOrder = ServiceOrder::create([
            'company_id'         => $this->company->id,
            'batch_id'           => $batch->id,
            'code'               => 'SO-20260605-092008-5727',
            'status'             => 'APPROVED',
            'service_type'       => 'IATF',
            'planned_start_date' => date('Y-m-d'),
        ]);

        // 7. Create female caravan
        $this->femaleCaravan = Caravan::create([
            'company_id'     => $this->company->id,
            'identification' => 'CAR-6-3-466',
            'sex'            => AnimalSex::FEMALE,
            'category'       => 'vaca',
            'teeth'          => 4,
            'is_empty'       => true,
        ]);

        // Associate female with the service order by adding it to service_order_females or similar if needed
        // Let's check how females are linked to service orders in ServiceOrderTest or by db schema.
        DB::table('service_order_females')->insert([
            'company_id'        => $this->company->id,
            'service_order_id'  => $this->serviceOrder->id,
            'female_caravan_id' => $this->femaleCaravan->id,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);
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

    public function test_can_import_gestation_ocr_pregnant_diagnosis_successfully(): void
    {
        $response = $this->actingAs($this->user)
            ->withHeaders(['X-Company-Id' => (string) $this->company->id])
            ->postJson('http://test.localhost/api/caravans/import-gestation-ocr', [
                'service_order_id' => $this->serviceOrder->id,
                'diagnosis_date'   => '2026-06-05',
                'rows'             => [
                    [
                        'identification'  => 'CAR-6-3-466',
                        'diagnostico'     => 'PREGNANT',
                        'gestation_stage' => 'cuerpo',
                    ]
                ]
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('status', 'success');
        $response->assertJsonPath('data.processed', 1);
        $response->assertJsonCount(0, 'data.errors');

        // Check database
        $this->femaleCaravan->refresh();
        $this->assertFalse((bool)$this->femaleCaravan->is_empty); // Pregnant now

        $gestation = CaravanGestation::where('caravan_id', $this->femaleCaravan->id)->first();
        $this->assertNotNull($gestation);
        $this->assertEquals(GestationStage::BODY, $gestation->gestation_stage);
        $this->assertEquals($this->serviceOrder->id, $gestation->service_order_id);
        $this->assertTrue((bool)$gestation->is_current);
    }

    public function test_validation_requires_service_order_id(): void
    {
        $response = $this->actingAs($this->user)
            ->withHeaders(['X-Company-Id' => (string) $this->company->id])
            ->postJson('http://test.localhost/api/caravans/import-gestation-ocr', [
                'diagnosis_date'   => '2026-06-05',
                'rows'             => [
                    [
                        'identification'  => 'CAR-6-3-466',
                        'diagnostico'     => 'PREGNANT',
                        'gestation_stage' => 'cuerpo',
                    ]
                ]
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['service_order_id']);
    }
}
