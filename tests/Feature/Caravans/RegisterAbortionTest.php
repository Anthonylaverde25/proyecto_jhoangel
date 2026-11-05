<?php

declare(strict_types=1);

namespace Tests\Feature\Caravans;

use App\Core\Enums\CategoryChangeCause;
use App\Core\Enums\CategoryChangeSource;
use App\Models\AnimalCategory;
use App\Models\AnimalSubcategory;
use App\Models\Batch;
use App\Models\Caravan;
use App\Models\CaravanCategoryChange;
use App\Models\CaravanGestation;
use App\Models\Company;
use App\Models\GestationLossReason;
use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class RegisterAbortionTest extends TestCase
{
    private Tenant $tenant;
    private Company $company;
    private Company $otherCompany;
    private Batch $batch;
    private int $vaquillonaCatId;
    private int $reposicionSubcatId;
    private int $vacaCatId;
    private int $rodeoGeneralSubcatId;

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
        $this->vaquillonaCatId = (int) $categories['VAQUILLONA'];
        $this->vacaCatId = (int) $categories['VACA'];

        $subcategories = AnimalSubcategory::pluck('id', 'code');
        $this->reposicionSubcatId = (int) ($subcategories['REPOSICION'] ?? 0);
        $this->rodeoGeneralSubcatId = (int) ($subcategories['RODEO_GENERAL'] ?? 0);

        GestationLossReason::firstOrCreate(
            ['code' => 'ABORTION', 'company_id' => $this->company->id],
            ['name' => 'Aborto', 'description' => 'Aborto espontáneo', 'is_active' => true]
        );
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        parent::tearDown();
    }

    public function test_vaquillona_abortion_without_becomes_cow_keeps_category_and_no_change_event(): void
    {
        $female = Caravan::create([
            'company_id' => $this->company->id,
            'batch_id' => $this->batch->id,
            'identification' => 'VAQ-ABT-01',
            'sex' => 'H',
            'category_id' => $this->vaquillonaCatId,
            'subcategory_id' => $this->reposicionSubcatId,
            'teeth' => 2,
        ]);

        CaravanGestation::create([
            'caravan_id' => $female->id,
            'is_current' => true,
            'start_date' => now()->subMonths(4)->toDateString(),
        ]);

        $response = $this->withHeader('X-Company-ID', (string) $this->company->id)
            ->postJson("http://test.localhost/api/caravans/{$female->id}/abortion", [
                'aborted_on' => now()->subDays(2)->toDateString(),
                'notes' => 'Aborto temprano en potrero',
                'becomes_cow' => false,
            ]);

        $response->assertStatus(200);

        $female->refresh();
        $this->assertSame($this->vaquillonaCatId, (int) $female->category_id);
        $this->assertSame($this->reposicionSubcatId, (int) $female->subcategory_id);

        $gestation = CaravanGestation::where('caravan_id', $female->id)->first();
        $this->assertFalse((bool) $gestation->is_current);
        $this->assertFalse((bool) $gestation->success);

        // No category change event recorded
        $this->assertDatabaseMissing('caravan_category_changes', [
            'caravan_id' => $female->id,
        ]);
    }

    public function test_vaquillona_abortion_with_becomes_cow_promotes_to_vaca_rodeo_general_and_records_event(): void
    {
        $female = Caravan::create([
            'company_id' => $this->company->id,
            'batch_id' => $this->batch->id,
            'identification' => 'VAQ-ABT-02',
            'sex' => 'H',
            'category_id' => $this->vaquillonaCatId,
            'subcategory_id' => $this->reposicionSubcatId,
            'teeth' => 4,
        ]);

        $gestation = CaravanGestation::create([
            'caravan_id' => $female->id,
            'is_current' => true,
            'start_date' => now()->subMonths(7)->toDateString(),
        ]);

        $abortedOn = now()->subDays(3)->toDateString();

        $response = $this->withHeader('X-Company-ID', (string) $this->company->id)
            ->postJson("http://test.localhost/api/caravans/{$female->id}/abortion", [
                'aborted_on' => $abortedOn,
                'notes' => 'Aborto tardío con ubre cargada, se maneja como vaca',
                'becomes_cow' => true,
            ]);

        $response->assertStatus(200);

        $female->refresh();
        $this->assertSame($this->vacaCatId, (int) $female->category_id);
        $this->assertSame($this->rodeoGeneralSubcatId, (int) $female->subcategory_id);

        $change = CaravanCategoryChange::where('caravan_id', $female->id)->first();
        $this->assertNotNull($change);
        $this->assertSame($this->vaquillonaCatId, (int) $change->from_category_id);
        $this->assertSame($this->reposicionSubcatId, (int) $change->from_subcategory_id);
        $this->assertSame($this->vacaCatId, (int) $change->to_category_id);
        $this->assertSame($this->rodeoGeneralSubcatId, (int) $change->to_subcategory_id);
        $this->assertSame(CategoryChangeCause::ABORTION_AS_CALVING->value, $change->cause);
        $this->assertSame($abortedOn, $change->occurred_on->format('Y-m-d'));
        $this->assertSame(CategoryChangeSource::GESTATION->value, $change->source_type);
        $this->assertSame($gestation->id, (int) $change->source_id);
    }

    public function test_vaca_abortion_refuses_becomes_cow_with_422(): void
    {
        $cow = Caravan::create([
            'company_id' => $this->company->id,
            'batch_id' => $this->batch->id,
            'identification' => 'COW-ABT-01',
            'sex' => 'H',
            'category_id' => $this->vacaCatId,
            'subcategory_id' => $this->rodeoGeneralSubcatId,
            'teeth' => 6,
        ]);

        CaravanGestation::create([
            'caravan_id' => $cow->id,
            'is_current' => true,
            'start_date' => now()->subMonths(5)->toDateString(),
        ]);

        $response = $this->withHeader('X-Company-ID', (string) $this->company->id)
            ->postJson("http://test.localhost/api/caravans/{$cow->id}/abortion", [
                'aborted_on' => now()->subDays(1)->toDateString(),
                'becomes_cow' => true,
            ]);

        $response->assertStatus(422);
    }

    public function test_vaca_abortion_without_becomes_cow_succeeds(): void
    {
        $cow = Caravan::create([
            'company_id' => $this->company->id,
            'batch_id' => $this->batch->id,
            'identification' => 'COW-ABT-02',
            'sex' => 'H',
            'category_id' => $this->vacaCatId,
            'subcategory_id' => $this->rodeoGeneralSubcatId,
            'teeth' => 6,
        ]);

        CaravanGestation::create([
            'caravan_id' => $cow->id,
            'is_current' => true,
            'start_date' => now()->subMonths(5)->toDateString(),
        ]);

        $response = $this->withHeader('X-Company-ID', (string) $this->company->id)
            ->postJson("http://test.localhost/api/caravans/{$cow->id}/abortion", [
                'aborted_on' => now()->subDays(1)->toDateString(),
                'becomes_cow' => false,
            ]);

        $response->assertStatus(200);

        $cow->refresh();
        $this->assertSame($this->vacaCatId, (int) $cow->category_id);
    }

    public function test_female_without_active_gestation_is_refused(): void
    {
        $female = Caravan::create([
            'company_id' => $this->company->id,
            'batch_id' => $this->batch->id,
            'identification' => 'VAQ-NO-GEST',
            'sex' => 'H',
            'category_id' => $this->vaquillonaCatId,
            'teeth' => 2,
        ]);

        $response = $this->withHeader('X-Company-ID', (string) $this->company->id)
            ->postJson("http://test.localhost/api/caravans/{$female->id}/abortion", [
                'aborted_on' => now()->toDateString(),
            ]);

        $response->assertStatus(422);
    }

    public function test_future_date_or_date_before_gestation_start_is_refused(): void
    {
        $female = Caravan::create([
            'company_id' => $this->company->id,
            'batch_id' => $this->batch->id,
            'identification' => 'VAQ-DATES',
            'sex' => 'H',
            'category_id' => $this->vaquillonaCatId,
            'teeth' => 2,
        ]);

        $startDate = now()->subMonths(3)->toDateString();
        CaravanGestation::create([
            'caravan_id' => $female->id,
            'is_current' => true,
            'start_date' => $startDate,
        ]);

        // Future date
        $future = now()->addDays(5)->toDateString();
        $response = $this->withHeader('X-Company-ID', (string) $this->company->id)
            ->postJson("http://test.localhost/api/caravans/{$female->id}/abortion", [
                'aborted_on' => $future,
            ]);
        $response->assertStatus(422);

        // Before start date
        $beforeStart = now()->subMonths(4)->toDateString();
        $response2 = $this->withHeader('X-Company-ID', (string) $this->company->id)
            ->postJson("http://test.localhost/api/caravans/{$female->id}/abortion", [
                'aborted_on' => $beforeStart,
            ]);
        $response2->assertStatus(422);
    }
}

