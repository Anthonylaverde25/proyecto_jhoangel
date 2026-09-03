<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\AnimalCategory;
use App\Models\Batch;
use App\Models\BatchType;
use App\Models\Caravan;
use App\Models\CaravanMovement;
use App\Models\Company;
use App\Models\ServiceOrder;
use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class CreateServiceBatchApiTest extends TestCase
{
    private Tenant $tenant;
    private Company $company;
    private AnimalCategory $femaleCat;
    private AnimalCategory $maleCat;
    private BatchType $serviceBatchType;
    private Activity $criaActivity;

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

        // Seed or find categories
        $this->femaleCat = AnimalCategory::firstOrCreate(
            ['code' => 'VAQUILLONA'],
            ['name' => 'Vaquillona', 'sex' => 'H']
        );

        $this->maleCat = AnimalCategory::firstOrCreate(
            ['code' => 'TORO'],
            ['name' => 'Toro', 'sex' => 'M']
        );

        $this->serviceBatchType = BatchType::firstOrCreate(
            ['company_id' => $this->company->id, 'code' => 'SERVICE'],
            ['name' => 'Servicio / Entore', 'is_active' => true]
        );

        $this->criaActivity = Activity::firstOrCreate(
            ['code' => 'CRIA'],
            ['name' => 'Cría', 'is_active' => true]
        );
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

    public function test_creates_service_batch_atomically_and_moves_caravans(): void
    {
        $originBatch = Batch::create([
            'company_id' => $this->company->id,
            'name' => 'Lote Origen Recría',
            'is_active' => true,
        ]);

        $female = Caravan::create([
            'company_id' => $this->company->id,
            'batch_id' => $originBatch->id,
            'identification' => 'H-100',
            'teeth' => 2,
            'sex' => 'H',
            'category_id' => $this->femaleCat->id,
        ]);

        $male = Caravan::create([
            'company_id' => $this->company->id,
            'batch_id' => null,
            'identification' => 'T-200',
            'teeth' => 4,
            'sex' => 'M',
            'category_id' => $this->maleCat->id,
        ]);

        $payload = [
            'name' => 'Lote Entore Vaquillonas 2026',
            'female_category_id' => $this->femaleCat->id,
            'male_category_id' => $this->maleCat->id,
            'female_caravan_ids' => [$female->id],
            'male_caravan_ids' => [$male->id],
            'target_bull_ratio' => 3.0,
            'planned_start_date' => '2026-10-01',
            'planned_end_date' => '2026-12-15',
            'auto_create_service_order' => true,
        ];

        $response = $this->postJson('http://test.localhost/api/batches/service', $payload, [
            'X-Company-ID' => (string) $this->company->id,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('name', 'Lote Entore Vaquillonas 2026');
        $response->assertJsonPath('is_service_batch', true);
        $response->assertJsonPath('service_detail.female_category_id', $this->femaleCat->id);
        $response->assertJsonPath('service_detail.male_category_id', $this->maleCat->id);

        $createdBatchId = $response->json('id');

        // Check Caravan transfers
        $this->assertEquals($createdBatchId, $female->fresh()->batch_id);
        $this->assertEquals($createdBatchId, $male->fresh()->batch_id);

        // Check Caravan Movements
        $this->assertDatabaseHas('caravan_movements', [
            'caravan_id' => $female->id,
            'from_batch_id' => $originBatch->id,
            'to_batch_id' => $createdBatchId,
            'type' => 'TRANSFER',
        ]);

        // Check Auto-generated Service Order
        $this->assertDatabaseHas('service_orders', [
            'batch_id' => $createdBatchId,
            'status' => 'APPROVED',
        ]);
    }

    public function test_rejects_female_with_mismatched_category(): void
    {
        $cowCat = AnimalCategory::firstOrCreate(
            ['code' => 'VACA'],
            ['name' => 'Vaca', 'sex' => 'H']
        );

        $cow = Caravan::create([
            'company_id' => $this->company->id,
            'batch_id' => null,
            'identification' => 'H-COW-99',
            'teeth' => 6,
            'sex' => 'H',
            'category_id' => $cowCat->id,
        ]);

        $payload = [
            'name' => 'Lote Invalido',
            'female_category_id' => $this->femaleCat->id, // Requires Vaquillona, but passed Vaca
            'male_category_id' => $this->maleCat->id,
            'female_caravan_ids' => [$cow->id],
            'male_caravan_ids' => [],
        ];

        $response = $this->postJson('http://test.localhost/api/batches/service', $payload, [
            'X-Company-ID' => (string) $this->company->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['message']);
    }
}
