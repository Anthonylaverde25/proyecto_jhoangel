<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\Caravan;
use App\Models\CaravanMovement;
use App\Models\Company;
use App\Models\ServiceOrder;
use App\Models\Tenant;
use App\Models\User;
use App\Core\Enums\AnimalSex;
use App\Core\Enums\AnimalCategory;
use App\Core\Enums\ServiceOrderStatus;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ServiceOrderTest extends TestCase
{
    private Tenant $tenant;
    private Company $company;
    private User $user;
    private Batch $sourceBatch;
    private Batch $targetBatch;
    private Caravan $bull1;
    private Caravan $bull2;
    private Caravan $cow1;
    private Caravan $cow2;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Manually migrate Landlord database
        Artisan::call('migrate');

        // 2. Create Tenant (triggers tenant migrations)
        $this->tenant = Tenant::create(['id' => 'test-tenant-' . uniqid()]);
        $this->tenant->domains()->create(['domain' => 'test.localhost']);

        // 3. Initialize Tenancy context
        tenancy()->initialize($this->tenant);

        // 4. Get seeded company and user
        $this->company = Company::first();
        $this->assertNotNull($this->company);

        // Mock CompanyContext
        $companyContext = new \App\Core\Contexts\CompanyContext();
        $companyContext->setCompanyId($this->company->id);
        $this->app->instance(\App\Core\Interfaces\ICompanyContext::class, $companyContext);

        // Create or get user
        $this->user = User::first() ?? User::create([
            'company_id' => $this->company->id,
            'name'       => 'Test User',
            'email'      => 'test@example.com',
            'password'   => bcrypt('password'),
        ]);

        // Create batches
        $this->sourceBatch = Batch::create([
            'company_id' => $this->company->id,
            'name'       => 'Source Batch',
            'is_active'  => true,
        ]);

        $this->targetBatch = Batch::create([
            'company_id' => $this->company->id,
            'name'       => 'Target Service Batch',
            'is_active'  => true,
        ]);

        // Create animals
        $this->bull1 = Caravan::create([
            'company_id'     => $this->company->id,
            'identification' => 99901,
            'sex'            => AnimalSex::MALE,
            'category'       => AnimalCategory::TORO,
            'batch_id'       => $this->sourceBatch->id,
        ]);

        $this->bull2 = Caravan::create([
            'company_id'     => $this->company->id,
            'identification' => 99902,
            'sex'            => AnimalSex::MALE,
            'category'       => AnimalCategory::TORO,
            'batch_id'       => $this->sourceBatch->id,
        ]);

        $this->cow1 = Caravan::create([
            'company_id'     => $this->company->id,
            'identification' => 99903,
            'sex'            => AnimalSex::FEMALE,
            'category'       => AnimalCategory::VACA,
            'batch_id'       => $this->sourceBatch->id,
        ]);

        $this->cow2 = Caravan::create([
            'company_id'     => $this->company->id,
            'identification' => 99904,
            'sex'            => AnimalSex::FEMALE,
            'category'       => AnimalCategory::VACA,
            'batch_id'       => $this->sourceBatch->id,
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

    public function test_can_create_service_order_in_draft(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-ID', (string)$this->company->id)
            ->postJson('http://test.localhost/api/service-orders', [
                'batch_id'             => $this->targetBatch->id,
                'code'                 => 'SO-TEST-001',
                'planned_start_date'   => '2026-06-01',
                'observations'         => 'Test observations',
                'male_caravan_ids'     => [$this->bull1->id, $this->bull2->id],
                'female_caravan_ids'   => [$this->cow1->id, $this->cow2->id],
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('status', ServiceOrderStatus::DRAFT->value);
        $response->assertJsonPath('code', 'SO-TEST-001');

        $this->assertDatabaseHas('service_orders', [
            'code'   => 'SO-TEST-001',
            'status' => ServiceOrderStatus::DRAFT->value,
        ]);

        $this->assertDatabaseHas('service_order_males', [
            'male_caravan_id' => $this->bull1->id,
        ]);

        $this->assertDatabaseHas('service_order_females', [
            'female_caravan_id' => $this->cow1->id,
        ]);
    }

    public function test_cannot_create_service_order_with_conflicting_animals(): void
    {
        // 1. Create a service order first with bull1
        $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-ID', (string)$this->company->id)
            ->postJson('http://test.localhost/api/service-orders', [
                'batch_id'             => $this->targetBatch->id,
                'code'                 => 'SO-FIRST-001',
                'planned_start_date'   => '2026-06-01',
                'male_caravan_ids'     => [$this->bull1->id],
                'female_caravan_ids'   => [$this->cow1->id],
            ])
            ->assertStatus(201);

        // 2. Try to create a second order using bull1 (should fail due to overlap)
        $response = $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-ID', (string)$this->company->id)
            ->postJson('http://test.localhost/api/service-orders', [
                'batch_id'             => $this->targetBatch->id,
                'code'                 => 'SO-CONFLICT-002',
                'planned_start_date'   => '2026-06-01',
                'male_caravan_ids'     => [$this->bull1->id, $this->bull2->id],
                'female_caravan_ids'   => [$this->cow2->id],
            ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['message']);
    }

    public function test_complete_state_flow_of_service_order(): void
    {
        // 1. Create draft
        $responseCreate = $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-ID', (string)$this->company->id)
            ->postJson('http://test.localhost/api/service-orders', [
                'batch_id'             => $this->targetBatch->id,
                'code'                 => 'SO-FLOW-001',
                'planned_start_date'   => '2026-06-01',
                'male_caravan_ids'     => [$this->bull1->id],
                'female_caravan_ids'   => [$this->cow1->id],
            ]);
        $orderId = $responseCreate->json('id');

        // 2. Submit for review
        $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-ID', (string)$this->company->id)
            ->postJson("http://test.localhost/api/service-orders/{$orderId}/submit-review")
            ->assertStatus(200)
            ->assertJsonPath('status', ServiceOrderStatus::PENDING_REVIEW->value);

        // 3. Review pass
        $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-ID', (string)$this->company->id)
            ->postJson("http://test.localhost/api/service-orders/{$orderId}/review", [
                'approve' => true,
            ])
            ->assertStatus(200)
            ->assertJsonPath('status', ServiceOrderStatus::PENDING_APPROVAL->value);

        // 4. Approve
        $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-ID', (string)$this->company->id)
            ->postJson("http://test.localhost/api/service-orders/{$orderId}/approve", [
                'approve' => true,
            ])
            ->assertStatus(200)
            ->assertJsonPath('status', ServiceOrderStatus::APPROVED->value);

        // 5. Execute (Mover animales al lote destino)
        $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-ID', (string)$this->company->id)
            ->postJson("http://test.localhost/api/service-orders/{$orderId}/execute")
            ->assertStatus(200)
            ->assertJsonPath('status', ServiceOrderStatus::IN_PROGRESS->value);

        // Check animal locations updated
        $this->assertEquals($this->targetBatch->id, Caravan::find($this->bull1->id)->batch_id);
        $this->assertEquals($this->targetBatch->id, Caravan::find($this->cow1->id)->batch_id);

        // Check movement records created
        $this->assertDatabaseHas('caravan_movements', [
            'caravan_id' => $this->bull1->id,
            'type'       => 'TRANSFER',
        ]);
        $this->assertDatabaseHas('caravan_movements', [
            'caravan_id' => $this->cow1->id,
            'type'       => 'TRANSFER',
        ]);

        // 6. Complete order
        $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-ID', (string)$this->company->id)
            ->postJson("http://test.localhost/api/service-orders/{$orderId}/complete", [
                'observations' => 'Finished service season.',
            ])
            ->assertStatus(200)
            ->assertJsonPath('status', ServiceOrderStatus::COMPLETED->value)
            ->assertJsonPath('observations', 'Finished service season.');
    }
}
