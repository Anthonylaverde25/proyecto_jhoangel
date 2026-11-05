<?php

declare(strict_types=1);

namespace Tests\Feature\Castrations;

use App\Core\Enums\CastrationMethod;
use App\Core\Enums\CategoryChangeCause;
use App\Core\Enums\CategoryChangeSource;
use App\Models\AnimalCategory;
use App\Models\Batch;
use App\Models\Caravan;
use App\Models\CaravanCastration;
use App\Models\CaravanCategoryChange;
use App\Models\Company;
use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class VoidCastrationTest extends TestCase
{
    private Tenant $tenant;
    private Company $company;
    private Batch $batch;
    private int $terneroCatId;
    private int $toritoCatId;
    private int $novillitoCatId;
    private int $novilloCatId;

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

        $this->batch = Batch::first();

        $categories = AnimalCategory::pluck('id', 'code');
        $this->terneroCatId = (int) $categories['TERNERO'];
        $this->toritoCatId = (int) $categories['TORITO'];
        $this->novillitoCatId = (int) $categories['NOVILLITO'];
        $this->novilloCatId = (int) $categories['NOVILLO'];
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        parent::tearDown();
    }

    public function test_void_calf_castration_allows_castrating_again(): void
    {
        $calf = Caravan::create([
            'company_id' => $this->company->id,
            'batch_id' => $this->batch->id,
            'identification' => 'CALF-VOID-01',
            'sex' => 'M',
            'category_id' => $this->terneroCatId,
            'teeth' => 0,
        ]);

        $castration = CaravanCastration::create([
            'company_id' => $this->company->id,
            'caravan_id' => $calf->id,
            'castrated_on' => '2026-10-15',
            'method' => CastrationMethod::BAND->value,
            'from_category_id' => $this->terneroCatId,
        ]);

        $response = $this->withHeader('X-Company-ID', (string) $this->company->id)
            ->postJson("http://test.localhost/api/caravans/castrations/{$castration->id}/void", [
                'reason' => 'Cargada por error en caravana equivocada',
            ]);

        $response->assertStatus(200);

        $castration->refresh();
        $this->assertNotNull($castration->voided_at);
        $this->assertSame('Cargada por error en caravana equivocada', $castration->void_reason);

        // Can castrate again now that active castration is voided
        $reCastrateResponse = $this->withHeader('X-Company-ID', (string) $this->company->id)
            ->postJson('http://test.localhost/api/caravans/castrations', [
                'castrated_on' => '2026-10-30',
                'items' => [
                    ['caravan_id' => $calf->id],
                ],
            ]);

        $reCastrateResponse->assertStatus(201);
    }

    public function test_void_torito_castration_reverts_to_torito_with_correction_event(): void
    {
        $animal = Caravan::create([
            'company_id' => $this->company->id,
            'batch_id' => $this->batch->id,
            'identification' => 'TORITO-VOID-01',
            'sex' => 'M',
            'category_id' => $this->novillitoCatId,
            'teeth' => 2,
        ]);

        $castration = CaravanCastration::create([
            'company_id' => $this->company->id,
            'caravan_id' => $animal->id,
            'castrated_on' => '2026-10-15',
            'method' => CastrationMethod::SURGICAL->value,
            'from_category_id' => $this->toritoCatId,
        ]);

        CaravanCategoryChange::create([
            'company_id' => $this->company->id,
            'caravan_id' => $animal->id,
            'from_category_id' => $this->toritoCatId,
            'to_category_id' => $this->novillitoCatId,
            'cause' => CategoryChangeCause::CASTRATION->value,
            'occurred_on' => '2026-10-15',
            'source_type' => CategoryChangeSource::CASTRATION->value,
            'source_id' => $castration->id,
        ]);

        $response = $this->withHeader('X-Company-ID', (string) $this->company->id)
            ->postJson("http://test.localhost/api/caravans/castrations/{$castration->id}/void", [
                'reason' => 'Error de digitación',
            ]);

        $response->assertStatus(200);

        // Reverted to TORITO
        $animal->refresh();
        $this->assertSame($this->toritoCatId, (int) $animal->category_id);

        // CORRECTION event exists
        $latestChange = CaravanCategoryChange::where('caravan_id', $animal->id)
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($latestChange);
        $this->assertSame(CategoryChangeCause::CORRECTION->value, $latestChange->cause);
        $this->assertSame($this->novillitoCatId, (int) $latestChange->from_category_id);
        $this->assertSame($this->toritoCatId, (int) $latestChange->to_category_id);
    }

    public function test_refuses_voiding_if_category_changed_after_castration(): void
    {
        $animal = Caravan::create([
            'company_id' => $this->company->id,
            'batch_id' => $this->batch->id,
            'identification' => 'TORITO-CS11-01',
            'sex' => 'M',
            'category_id' => $this->novilloCatId,
            'teeth' => 4,
        ]);

        $castration = CaravanCastration::create([
            'company_id' => $this->company->id,
            'caravan_id' => $animal->id,
            'castrated_on' => '2026-05-15',
            'from_category_id' => $this->toritoCatId,
        ]);

        // Event 1: Torito -> Novillito
        CaravanCategoryChange::create([
            'company_id' => $this->company->id,
            'caravan_id' => $animal->id,
            'from_category_id' => $this->toritoCatId,
            'to_category_id' => $this->novillitoCatId,
            'cause' => CategoryChangeCause::CASTRATION->value,
            'occurred_on' => '2026-05-15',
            'source_type' => CategoryChangeSource::CASTRATION->value,
            'source_id' => $castration->id,
        ]);

        // Event 2: Novillito -> Novillo (later change)
        CaravanCategoryChange::create([
            'company_id' => $this->company->id,
            'caravan_id' => $animal->id,
            'from_category_id' => $this->novillitoCatId,
            'to_category_id' => $this->novilloCatId,
            'cause' => CategoryChangeCause::CORRECTION->value,
            'occurred_on' => '2026-10-01',
        ]);

        $response = $this->withHeader('X-Company-ID', (string) $this->company->id)
            ->postJson("http://test.localhost/api/caravans/castrations/{$castration->id}/void", [
                'reason' => 'Intento tardío',
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('categoría cambió después de la castración', $response->json('message'));
    }
}
