<?php

declare(strict_types=1);

namespace Tests\Feature\Castrations;

use App\Core\Enums\CastrationMethod;
use App\Core\Enums\CastrationStatus;
use App\Core\Enums\CategoryChangeCause;
use App\Core\Enums\CategoryChangeSource;
use App\Models\AnimalCategory;
use App\Models\Batch;
use App\Models\Caravan;
use App\Models\CaravanCastration;
use App\Models\CaravanCategoryChange;
use App\Models\CaravanDeath;
use App\Models\CaravanLineage;
use App\Models\Company;
use App\Models\DeathCause;
use App\Models\ExitOrder;
use App\Models\ExitOrderAnimal;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderMale;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class RegisterCastrationTest extends TestCase
{
    private const URL = 'http://test.localhost/api/caravans/castrations';

    private Tenant $tenant;
    private Company $company;
    private Company $otherCompany;
    private Batch $batch;
    private int $terneroCatId;
    private int $toritoCatId;
    private int $novillitoCatId;
    private int $toroCatId;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate');
        $this->tenant = Tenant::create(['id' => 'test-tenant-' . uniqid()]);
        $this->tenant->domains()->create(['domain' => 'test.localhost']);
        tenancy()->initialize($this->tenant);

        $this->company = Company::first();
        $this->assertNotNull($this->company);
        $this->otherCompany = Company::create(['name' => 'Otra Empresa', 'is_active' => true]);

        $companyContext = new \App\Core\Contexts\CompanyContext();
        $companyContext->setCompanyId($this->company->id);
        $this->app->instance(\App\Core\Interfaces\ICompanyContext::class, $companyContext);

        $this->batch = Batch::first();

        $categories = AnimalCategory::pluck('id', 'code');
        $this->terneroCatId = (int) $categories['TERNERO'];
        $this->toritoCatId = (int) $categories['TORITO'];
        $this->novillitoCatId = (int) $categories['NOVILLITO'];
        $this->toroCatId = (int) $categories['TORO'];
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        parent::tearDown();
    }

    public function test_calf_castration_keeps_category_and_leaves_no_category_change_event(): void
    {
        $calf = Caravan::create([
            'company_id' => $this->company->id,
            'batch_id' => $this->batch->id,
            'identification' => 'CALF-01',
            'sex' => 'M',
            'category_id' => $this->terneroCatId,
            'teeth' => 0,
        ]);

        $response = $this->withHeader('X-Company-ID', (string) $this->company->id)
            ->postJson(self::URL, [
                'castrated_on' => '2026-10-30',
                'method' => CastrationMethod::BAND->value,
                'notes' => 'Castrado con goma',
                'items' => [
                    ['caravan_id' => $calf->id],
                ],
            ]);

        $response->assertStatus(201);
        $this->assertCount(1, $response->json());

        // Caravan keeps TERNERO
        $calf->refresh();
        $this->assertSame($this->terneroCatId, (int) $calf->category_id);

        // CaravanCastration created
        $castration = CaravanCastration::where('caravan_id', $calf->id)->first();
        $this->assertNotNull($castration);
        $this->assertSame('2026-10-30', $castration->castrated_on->format('Y-m-d'));
        $this->assertSame(CastrationMethod::BAND->value, $castration->method);
        $this->assertSame('Castrado con goma', $castration->notes);
        $this->assertNull($castration->voided_at);

        // No category change event recorded
        $this->assertDatabaseMissing('caravan_category_changes', [
            'caravan_id' => $calf->id,
        ]);
    }

    public function test_torito_castration_reclassifies_to_novillito_and_records_event(): void
    {
        $torito = Caravan::create([
            'company_id' => $this->company->id,
            'batch_id' => $this->batch->id,
            'identification' => 'TORITO-01',
            'sex' => 'M',
            'category_id' => $this->toritoCatId,
            'teeth' => 2,
        ]);

        $response = $this->withHeader('X-Company-ID', (string) $this->company->id)
            ->postJson(self::URL, [
                'castrated_on' => '2026-10-30',
                'method' => CastrationMethod::SURGICAL->value,
                'notes' => 'Torito descartado',
                'items' => [
                    [
                        'caravan_id' => $torito->id,
                        'target_category_code' => 'NOVILLITO',
                    ],
                ],
            ]);

        $response->assertStatus(201);

        $torito->refresh();
        $this->assertSame($this->novillitoCatId, (int) $torito->category_id);
        $this->assertNull($torito->subcategory_id);

        // Category change recorded
        $change = CaravanCategoryChange::where('caravan_id', $torito->id)->first();
        $this->assertNotNull($change);
        $this->assertSame($this->toritoCatId, (int) $change->from_category_id);
        $this->assertSame($this->novillitoCatId, (int) $change->to_category_id);
        $this->assertSame(CategoryChangeCause::CASTRATION->value, $change->cause);
        $this->assertSame('2026-10-30', $change->occurred_on->format('Y-m-d'));
        $this->assertSame(CategoryChangeSource::CASTRATION->value, $change->source_type);
    }

    public function test_torito_castration_with_target_category_id_reclassifies(): void
    {
        $torito = Caravan::create([
            'company_id' => $this->company->id,
            'batch_id' => $this->batch->id,
            'identification' => 'TORITO-ID-01',
            'sex' => 'M',
            'category_id' => $this->toritoCatId,
            'teeth' => 2,
        ]);

        $response = $this->withHeader('X-Company-ID', (string) $this->company->id)
            ->postJson(self::URL, [
                'castrated_on' => '2026-10-30',
                'method' => CastrationMethod::BURDIZZO->value,
                'items' => [
                    [
                        'caravan_id' => $torito->id,
                        'target_category_id' => $this->novillitoCatId,
                    ],
                ],
            ]);

        $response->assertStatus(201);

        $torito->refresh();
        $this->assertSame($this->novillitoCatId, (int) $torito->category_id);
    }

    public function test_row_refusals_and_all_or_nothing(): void
    {
        // 1. Female
        $female = Caravan::create([
            'company_id' => $this->company->id,
            'batch_id' => $this->batch->id,
            'identification' => 'FEMALE-01',
            'sex' => 'H',
            'category_id' => $this->terneroCatId,
            'teeth' => 0,
        ]);

        // 2. Novillito
        $novillito = Caravan::create([
            'company_id' => $this->company->id,
            'batch_id' => $this->batch->id,
            'identification' => 'NOV-01',
            'sex' => 'M',
            'category_id' => $this->novillitoCatId,
            'teeth' => 2,
        ]);

        // 3. Toro
        $toro = Caravan::create([
            'company_id' => $this->company->id,
            'batch_id' => $this->batch->id,
            'identification' => 'TORO-01',
            'sex' => 'M',
            'category_id' => $this->toroCatId,
            'teeth' => 6,
        ]);

        // 4. Eligible calf
        $eligibleCalf = Caravan::create([
            'company_id' => $this->company->id,
            'batch_id' => $this->batch->id,
            'identification' => 'ELIGIBLE-01',
            'sex' => 'M',
            'category_id' => $this->terneroCatId,
            'teeth' => 0,
        ]);

        $response = $this->withHeader('X-Company-ID', (string) $this->company->id)
            ->postJson(self::URL, [
                'castrated_on' => '2026-10-30',
                'items' => [
                    ['caravan_id' => $female->id],
                    ['caravan_id' => $novillito->id],
                    ['caravan_id' => $toro->id],
                    ['caravan_id' => $eligibleCalf->id],
                ],
            ]);

        $response->assertStatus(422);
        $errors = $response->json('errors');

        $this->assertStringContainsString('sólo se castran machos', $errors['items.0.caravan_id'][0]);
        $this->assertStringContainsString('ya es castrado por su categoría', $errors['items.1.caravan_id'][0]);
        $this->assertStringContainsString('un toro adulto no se castra', $errors['items.2.caravan_id'][0]);

        // All or nothing: eligible calf was NOT castrated
        $this->assertDatabaseMissing('caravan_castrations', [
            'caravan_id' => $eligibleCalf->id,
        ]);
    }

    public function test_refuses_future_date_and_date_before_birth(): void
    {
        $mother = Caravan::create([
            'company_id' => $this->company->id,
            'batch_id' => $this->batch->id,
            'identification' => 'MOTHER-01',
            'sex' => 'H',
            'teeth' => 6,
        ]);

        $calf = Caravan::create([
            'company_id' => $this->company->id,
            'batch_id' => $this->batch->id,
            'identification' => 'CALF-BIRTHTEST',
            'sex' => 'M',
            'category_id' => $this->terneroCatId,
            'teeth' => 0,
        ]);

        CaravanLineage::create([
            'caravan_id' => $calf->id,
            'mother_id' => $mother->id,
            'birth_date' => '2026-08-01',
        ]);

        // Date before birth
        $response = $this->withHeader('X-Company-ID', (string) $this->company->id)
            ->postJson(self::URL, [
                'castrated_on' => '2026-07-15',
                'items' => [
                    ['caravan_id' => $calf->id],
                ],
            ]);

        $response->assertStatus(422);
        $errors = $response->json('errors');
        $this->assertStringContainsString('no puede ser anterior al nacimiento', $errors['items.0.caravan_id'][0]);

        // Future date
        $responseFuture = $this->withHeader('X-Company-ID', (string) $this->company->id)
            ->postJson(self::URL, [
                'castrated_on' => date('Y-m-d', strtotime('+5 days')),
                'items' => [
                    ['caravan_id' => $calf->id],
                ],
            ]);

        $responseFuture->assertStatus(422);
    }

    public function test_refuses_torito_in_active_service_order(): void
    {
        $torito = Caravan::create([
            'company_id' => $this->company->id,
            'batch_id' => $this->batch->id,
            'identification' => 'BULL-SO-01',
            'sex' => 'M',
            'category_id' => $this->toritoCatId,
            'teeth' => 2,
        ]);

        $so = ServiceOrder::create([
            'company_id' => $this->company->id,
            'batch_id' => $this->batch->id,
            'code' => 'SO-TEST-001',
            'status' => 'IN_SERVICE',
            'planned_start_date' => '2026-10-01',
        ]);

        ServiceOrderMale::create([
            'company_id' => $this->company->id,
            'service_order_id' => $so->id,
            'male_caravan_id' => $torito->id,
            'status' => 'ACTIVE',
        ]);

        $response = $this->withHeader('X-Company-ID', (string) $this->company->id)
            ->postJson(self::URL, [
                'castrated_on' => '2026-10-30',
                'items' => [
                    [
                        'caravan_id' => $torito->id,
                        'target_category_code' => 'NOVILLITO',
                    ],
                ],
            ]);

        $response->assertStatus(422);
        $errors = $response->json('errors');
        $this->assertStringContainsString('trabaja en el servicio SO-TEST-001', $errors['items.0.caravan_id'][0]);
    }
}
