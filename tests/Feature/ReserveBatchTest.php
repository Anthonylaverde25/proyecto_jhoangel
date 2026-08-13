<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\BatchType;
use App\Models\Caravan;
use App\Models\CaravanMovement;
use App\Models\Company;
use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ReserveBatchTest extends TestCase
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

    public function test_get_or_create_reserve_batch_creates_system_batch_when_not_existing(): void
    {
        $response = $this->getJson('http://test.localhost/api/batches/reserve', [
            'X-Company-ID' => (string) $this->company->id,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('name', 'Lote Reserva | Animales Apartados');
        $response->assertJsonPath('is_system', true);
        $response->assertJsonPath('batch_type_code', 'RESERVE');

        // Second call returns the same batch without creating a duplicate
        $response2 = $this->getJson('http://test.localhost/api/batches/reserve', [
            'X-Company-ID' => (string) $this->company->id,
        ]);

        $response2->assertStatus(200);
        $this->assertEquals($response->json('id'), $response2->json('id'));
    }

    public function test_bulk_transfer_caravans_to_reserve_batch(): void
    {
        // 1. Create a regular source batch
        $sourceBatch = Batch::create([
            'company_id' => $this->company->id,
            'name' => 'Lote Rodeo General 1',
            'is_active' => true,
        ]);

        // 2. Create 2 test caravans in the source batch
        $caravan1 = Caravan::create([
            'company_id' => $this->company->id,
            'identification' => 'TEST-001',
            'sex' => 'H',
            'category' => 'vaca',
            'teeth' => 4,
            'batch_id' => $sourceBatch->id,
        ]);

        $caravan2 = Caravan::create([
            'company_id' => $this->company->id,
            'identification' => 'TEST-002',
            'sex' => 'H',
            'category' => 'vaquillona',
            'teeth' => 2,
            'batch_id' => $sourceBatch->id,
        ]);

        // 3. Execute bulk transfer to Reserve (omitting target_batch_id auto-assigns Reserve Batch)
        $response = $this->postJson('http://test.localhost/api/caravans/bulk-transfer', [
            'caravan_ids' => [$caravan1->id, $caravan2->id],
            'reason' => 'Apartado por alta consanguinidad (25%) detectada en Pedigree',
        ], [
            'X-Company-ID' => (string) $this->company->id,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'transferred_count' => 2,
            'target_batch_name' => 'Lote Reserva | Animales Apartados',
        ]);

        $targetBatchId = $response->json('target_batch_id');

        // 4. Assert caravans are now in the reserve batch
        $this->assertEquals($targetBatchId, $caravan1->fresh()->batch_id);
        $this->assertEquals($targetBatchId, $caravan2->fresh()->batch_id);

        // 5. Assert movements were registered
        $this->assertDatabaseHas('caravan_movements', [
            'caravan_id' => $caravan1->id,
            'type' => 'TRANSFER',
        ]);
        $this->assertDatabaseHas('caravan_movements', [
            'caravan_id' => $caravan2->id,
            'type' => 'TRANSFER',
        ]);
    }

}
