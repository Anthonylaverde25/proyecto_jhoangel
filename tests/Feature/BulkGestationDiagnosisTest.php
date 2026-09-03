<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Enums\AnimalCategory;
use App\Core\Enums\AnimalSex;
use App\Core\Enums\ServiceOrderStatus;
use App\Models\Activity;
use App\Models\Batch;
use App\Models\Caravan;
use App\Models\Company;
use App\Models\ServiceOrder;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class BulkGestationDiagnosisTest extends TestCase
{
    private Tenant $tenant;
    private Company $company;
    private User $user;
    private Batch $breedingBatch;
    private Batch $invernadaBatch;
    private ServiceOrder $serviceOrder;
    private Caravan $bull;
    private Caravan $pregnantCow;
    private Caravan $emptyCowToMove;
    private Caravan $emptyCowToKeep;

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

        $criaActivity = Activity::updateOrCreate(['code' => 'CRIA'], ['name' => 'Cría']);
        $invernadaActivity = Activity::updateOrCreate(['code' => 'INVERNADA'], ['name' => 'Invernada']);

        $this->breedingBatch = Batch::create([
            'company_id'  => $this->company->id,
            'name'        => 'Lote Cría 1',
            'is_active'   => true,
            'activity_id' => $criaActivity->id,
        ]);

        $this->invernadaBatch = Batch::create([
            'company_id'  => $this->company->id,
            'name'        => 'Lote Invernada 1',
            'is_active'   => true,
            'activity_id' => $invernadaActivity->id,
        ]);

        $this->bull = Caravan::create([
            'company_id'     => $this->company->id,
            'identification' => 'BULL-01',
            'sex'            => AnimalSex::MALE,
            'category'       => AnimalCategory::TORO,
            'batch_id'       => $this->breedingBatch->id,
        ]);

        $this->pregnantCow = Caravan::create([
            'company_id'     => $this->company->id,
            'identification' => 'COW-PREG-01',
            'sex'            => AnimalSex::FEMALE,
            'category'       => AnimalCategory::VACA,
            'batch_id'       => $this->breedingBatch->id,
        ]);

        $this->emptyCowToMove = Caravan::create([
            'company_id'     => $this->company->id,
            'identification' => 'COW-EMPTY-MOVE',
            'sex'            => AnimalSex::FEMALE,
            'category'       => AnimalCategory::VACA,
            'batch_id'       => $this->breedingBatch->id,
        ]);

        $this->emptyCowToKeep = Caravan::create([
            'company_id'     => $this->company->id,
            'identification' => 'COW-EMPTY-KEEP',
            'sex'            => AnimalSex::FEMALE,
            'category'       => AnimalCategory::VACA,
            'batch_id'       => $this->breedingBatch->id,
        ]);

        $responseCreate = $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-ID', (string)$this->company->id)
            ->postJson('http://test.localhost/api/service-orders', [
                'batch_id'           => $this->breedingBatch->id,
                'code'               => 'SO-TACTO-TEST',
                'planned_start_date' => '2026-06-01',
                'male_caravan_ids'   => [$this->bull->id],
                'female_caravan_ids' => [
                    $this->pregnantCow->id,
                    $this->emptyCowToMove->id,
                    $this->emptyCowToKeep->id,
                ],
            ]);

        $orderId = $responseCreate->json('id');

        $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-ID', (string)$this->company->id)
            ->postJson("http://test.localhost/api/service-orders/{$orderId}/approve", [
                'approve' => true,
            ]);

        $this->serviceOrder = ServiceOrder::find($orderId);
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

    public function test_bulk_gestation_diagnosis_with_pregnant_and_empty_relocation(): void
    {
        $payload = [
            'diagnoses' => [
                [
                    'caravan_id'       => $this->pregnantCow->id,
                    'service_order_id' => $this->serviceOrder->id,
                    'is_pregnant'      => true,
                    'gestation_stage'  => 'head',
                    'gestation_months' => 3.5,
                    'confirmed_sire_id'=> $this->bull->id,
                    'diagnosis_date'   => '2026-08-20',
                ],
                [
                    'caravan_id'                 => $this->emptyCowToMove->id,
                    'service_order_id'           => $this->serviceOrder->id,
                    'is_pregnant'                => false,
                    'diagnosis_date'             => '2026-08-20',
                    'empty_destination_batch_id' => $this->invernadaBatch->id,
                ],
                [
                    'caravan_id'                 => $this->emptyCowToKeep->id,
                    'service_order_id'           => $this->serviceOrder->id,
                    'is_pregnant'                => false,
                    'diagnosis_date'             => '2026-08-20',
                    'empty_destination_batch_id' => null,
                ],
            ]
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-ID', (string)$this->company->id)
            ->postJson('http://test.localhost/api/caravans/bulk-gestation-diagnosis', $payload);

        $response->assertStatus(201);

        // 1. Verify Pregnant Cow
        $freshPregnant = Caravan::find($this->pregnantCow->id);
        $this->assertEquals($this->breedingBatch->id, $freshPregnant->batch_id);
        $this->assertDatabaseHas('female_caravan_details', [
            'caravan_id' => $this->pregnantCow->id,
            'is_empty'   => false,
        ]);
        $this->assertDatabaseHas('caravan_gestations', [
            'caravan_id'       => $this->pregnantCow->id,
            'service_order_id' => $this->serviceOrder->id,
            'is_current'       => true,
            'gestation_stage'  => 'head',
            'gestation_months' => 3.5,
        ]);

        // 2. Verify Empty Cow Moved to Invernada
        $freshEmptyMoved = Caravan::find($this->emptyCowToMove->id);
        $this->assertEquals($this->invernadaBatch->id, $freshEmptyMoved->batch_id);
        $this->assertDatabaseHas('female_caravan_details', [
            'caravan_id' => $this->emptyCowToMove->id,
            'is_empty'   => true,
        ]);
        $this->assertDatabaseHas('caravan_movements', [
            'caravan_id'    => $this->emptyCowToMove->id,
            'company_id'    => $this->company->id,
            'from_batch_id' => $this->breedingBatch->id,
            'to_batch_id'   => $this->invernadaBatch->id,
            'type'          => 'TRANSFER',
        ]);

        // 3. Verify Empty Cow Kept in Breeding Batch
        $freshEmptyKept = Caravan::find($this->emptyCowToKeep->id);
        $this->assertEquals($this->breedingBatch->id, $freshEmptyKept->batch_id);
        $this->assertDatabaseHas('female_caravan_details', [
            'caravan_id' => $this->emptyCowToKeep->id,
            'is_empty'   => true,
        ]);
    }
}
