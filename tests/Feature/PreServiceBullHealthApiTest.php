<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BullHealthEvaluation;
use App\Models\Caravan;
use App\Models\Company;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VeterinaryDiagnosis;
use Database\Seeders\AnimalCategorySeeder;
use Database\Seeders\BullHealthSeeder;
use Database\Seeders\PathogenSeeder;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class PreServiceBullHealthApiTest extends TestCase
{
    private Tenant $tenant;
    private User $user;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate');
        $this->tenant = Tenant::create(['id' => 'test-preservice-' . uniqid()]);
        $this->tenant->domains()->create(['domain' => 'test-preservice.localhost']);
        tenancy()->initialize($this->tenant);

        Artisan::call('tenants:migrate');

        $this->company = Company::first() ?? Company::create([
            'name' => 'Cabaña Santa María',
            'cuit' => '30-71999999-9',
        ]);

        $companyContext = new \App\Core\Contexts\CompanyContext();
        $companyContext->setCompanyId($this->company->id);
        $this->app->instance(\App\Core\Interfaces\ICompanyContext::class, $companyContext);

        $this->user = User::first() ?? User::create([
            'company_id' => $this->company->id,
            'name' => 'Veterinario Test',
            'email' => 'vet@test.com',
            'password' => bcrypt('secret123'),
        ]);

        $this->seed(AnimalCategorySeeder::class);
        $this->seed(PathogenSeeder::class);
        $this->seed(BullHealthSeeder::class);
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        if (isset($this->tenant)) {
            $this->tenant->delete();
        }
        Artisan::call('migrate:rollback');
        parent::tearDown();
    }

    public function test_can_list_pathogens_catalog(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-ID', (string) $this->company->id)
            ->getJson('http://test-preservice.localhost/api/pathogens');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                '*' => ['id', 'code', 'name', 'category', 'is_disqualifying', 'description'],
            ],
        ]);

        $this->assertCount(8, $response->json('data'));
    }

    public function test_can_list_pre_service_bulls_with_health_status(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-ID', (string) $this->company->id)
            ->getJson('http://test-preservice.localhost/api/pre-service/bulls');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'company_id',
                    'caravan_id',
                    'caravan_number',
                    'last_evaluation_date',
                    'aplomo_notes',
                    'scrotal_circumference_cm',
                    'body_condition_score',
                    'libido',
                    'status',
                    'is_apt',
                    'is_under_treatment',
                    'is_unfit',
                    'active_diagnoses',
                ],
            ],
        ]);

        $data = $response->json('data');
        $seededBulls = array_values(array_filter($data, fn ($b) => str_starts_with((string) $b['caravan_number'], 'TR-')));
        $this->assertCount(30, $seededBulls);

        $aptCount = count(array_filter($seededBulls, fn ($b) => $b['status'] === 'APT'));
        $nonAptCount = count(array_filter($seededBulls, fn ($b) => $b['status'] !== 'APT'));

        $this->assertSame(15, $aptCount, "Debe haber exactamente 15 toros aptos para servicio.");
        $this->assertSame(15, $nonAptCount, "Debe haber exactamente 15 toros no aptos / en tratamiento.");
    }

    public function test_can_register_bull_health_evaluation_and_update_aptitude(): void
    {
        $caravan = Caravan::where('identification', 'TR-001')->firstOrFail();

        $payload = [
            'caravan_id' => $caravan->id,
            'last_evaluation_date' => '2026-09-02',
            'aplomo_notes' => 'Aplomos excelentes revisados en manga',
            'scrotal_circumference_cm' => 38.0,
            'body_condition_score' => 3.5,
            'libido' => 'MUY_ALTA',
            'observations' => 'Apto verificado',
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-ID', (string) $this->company->id)
            ->postJson('http://test-preservice.localhost/api/pre-service/bull-evaluations', $payload);

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'APT');
        $response->assertJsonPath('data.scrotal_circumference_cm', 38);
    }

    public function test_can_resolve_diagnosis_and_toro_becomes_apt(): void
    {
        // TR-025 is in treatment for Pietín
        $caravan = Caravan::where('identification', 'TR-025')->firstOrFail();
        $diagnosis = VeterinaryDiagnosis::where('caravan_id', $caravan->id)
            ->where('status', 'IN_TREATMENT')
            ->firstOrFail();

        // 1. Verify before resolving
        $evalBefore = BullHealthEvaluation::where('caravan_id', $caravan->id)->firstOrFail();
        $this->assertSame('IN_TREATMENT', $evalBefore->status);

        // 2. Resolve diagnosis (Alta médica)
        $response = $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-ID', (string) $this->company->id)
            ->patchJson("http://test-preservice.localhost/api/diagnoses/{$diagnosis->id}/resolve", [
                'resolution_date' => '2026-09-02',
                'notes' => 'Pezuña cicatrizada completamente tras pediluvio y antibiótico. Sin renguera.',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        // 3. Verify bull status is now reactively updated to APT
        $evalAfter = BullHealthEvaluation::where('caravan_id', $caravan->id)->firstOrFail();
        $this->assertSame('APT', $evalAfter->status);
    }

    public function test_cannot_create_service_order_with_unfit_bull(): void
    {
        $unfitBull = Caravan::where('identification', 'TR-016')->firstOrFail(); // TR-016 has Tritrichomonas foetus
        $female = Caravan::create([
            'company_id' => $this->company->id,
            'identification' => 'VACA-999',
            'sex' => \App\Core\Enums\AnimalSex::FEMALE,
            'teeth' => 4,
            'entry_weight' => 480.0,
        ]);

        $batch = \App\Models\Batch::create([
            'company_id' => $this->company->id,
            'name' => 'Lote Cría General',
            'activity_id' => \App\Models\Activity::firstOrCreate(['code' => 'CRIA'], ['name' => 'Cría'])->id,
            'is_active' => true,
        ]);

        $payload = [
            'batch_id' => $batch->id,
            'code' => 'SO-TEST-UNFIT',
            'planned_start_date' => '2026-09-02',
            'service_type' => 'multi',
            'male_caravan_ids' => [$unfitBull->id],
            'female_caravan_ids' => [$female->id],
            'observations' => 'Intento con toro infectado',
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-ID', (string) $this->company->id)
            ->postJson('http://test-preservice.localhost/api/service-orders', $payload);

        $response->assertStatus(422);
        $this->assertStringContainsString('no está apto para servicio', (string) $response->json('message'));
    }
}
