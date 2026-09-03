<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Enums\AnimalCategory;
use App\Core\Enums\AnimalSex;
use App\Models\Batch;
use App\Models\BatchType;
use App\Models\Caravan;
use App\Models\Company;
use App\Models\Farm;
use App\Models\Provider;
use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class CreateBatchWithExtraFieldsTest extends TestCase
{
    private Tenant $tenant;
    private Company $company;
    private BatchType $batchType;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate');
        $this->tenant = Tenant::create(['id' => 'test-tenant-' . uniqid()]);
        $this->tenant->domains()->create(['domain' => 'test.localhost']);
        tenancy()->initialize($this->tenant);

        $this->company = Company::first();
        $this->assertNotNull($this->company);

        // Assign active batch type
        $this->batchType = BatchType::firstOrCreate([
            'company_id' => $this->company->id,
            'code' => 'OPERATIONAL'
        ], [
            'name' => 'Operativo',
            'is_active' => true
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

    public function test_can_create_batch_with_extra_fields(): void
    {
        $response = $this->postJson('http://test.localhost/api/batches', [
            'name' => 'Lote Test Extra Fields',
            'batch_type_id' => $this->batchType->id,
            'knows_to_eat' => true,
            'age_in_months' => 12,
            'min_weight' => 250.50,
            'max_weight' => 380.00
        ], [
            'X-Company-ID' => (string) $this->company->id,
        ]);

        $response->assertStatus(201);
        $data = $response->json();

        $this->assertEquals('Lote Test Extra Fields', $data['name']);
        $this->assertTrue($data['knows_to_eat']);
        $this->assertEquals(12, $data['age_in_months']);
        $this->assertEquals(250.50, $data['min_weight']);
        $this->assertEquals(380.00, $data['max_weight']);

        // Check model persistence
        $batch = Batch::find($data['id']);
        $this->assertNotNull($batch);
        $this->assertTrue((bool)$batch->knows_to_eat);
        $this->assertEquals(12, $batch->age_in_months);
        $this->assertEquals(250.50, $batch->min_weight);
        $this->assertEquals(380.00, $batch->max_weight);
    }

    public function test_automatic_weight_range_recalculation_after_assignment(): void
    {
        // 1. Create own empty batch (with manual weights range)
        $ownBatch = Batch::create([
            'company_id' => $this->company->id,
            'name' => 'Lote Propio Automatizado',
            'batch_type_id' => $this->batchType->id,
            'farm_id' => null,
            'is_active' => true,
            'min_weight' => 100.0,
            'max_weight' => 200.0
        ]);

        // 2. Create provider and external farm
        $provider = Provider::create([
            'name' => 'Proveedor Test 3',
            'cuit' => '30-11112222-5',
            'is_active' => true
        ]);
        $externalFarm = Farm::create([
            'name' => 'Granja Proveedor 3',
            'renspa' => '01.002.3.00456/02',
            'provider_id' => $provider->id,
            'is_active' => true
        ]);
        $externalBatch = Batch::create([
            'company_id' => $this->company->id,
            'name' => 'Lote Externo 3',
            'farm_id' => $externalFarm->id,
            'is_active' => true
        ]);

        // 3. Create two caravans in external batch with weights 320kg and 440kg
        $caravan1 = Caravan::create([
            'company_id' => $this->company->id,
            'batch_id' => $externalBatch->id,
            'renspa' => '01.002.3.00456/02',
            'identification' => 'CAR-320',
            'teeth' => 2,
            'sex' => AnimalSex::FEMALE,
            'entry_weight' => 320.0
        ]);
        // Record current weight for caravan 1
        $caravan1->weights()->create([
            'weight' => 320.0,
            'weighing_date' => now()->toDateString(),
            'current' => true
        ]);

        $caravan2 = Caravan::create([
            'company_id' => $this->company->id,
            'batch_id' => $externalBatch->id,
            'renspa' => '01.002.3.00456/02',
            'identification' => 'CAR-440',
            'teeth' => 2,
            'sex' => AnimalSex::FEMALE,
            'entry_weight' => 440.0
        ]);
        // Record current weight for caravan 2
        $caravan2->weights()->create([
            'weight' => 440.0,
            'weighing_date' => now()->toDateString(),
            'current' => true
        ]);

        // 4. Assign these caravans to own batch
        $response = $this->postJson('http://test.localhost/api/batches/assign-to-own', [
            'caravan_ids' => [$caravan1->id, $caravan2->id],
            'target_batch_id' => $ownBatch->id,
            'entry_date' => now()->toDateString()
        ], [
            'X-Company-ID' => (string) $this->company->id,
        ]);

        $response->assertStatus(200);

        // 5. Assert batch min_weight and max_weight are updated dynamically
        $ownBatch->refresh();
        $this->assertEquals(320.0, $ownBatch->min_weight);
        $this->assertEquals(440.0, $ownBatch->max_weight);
        $this->assertEquals(380.0, $ownBatch->current_weight); // (320 + 440) / 2
    }
}
