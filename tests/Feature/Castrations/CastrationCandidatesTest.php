<?php

declare(strict_types=1);

namespace Tests\Feature\Castrations;

use App\Models\AnimalCategory;
use App\Models\Batch;
use App\Models\Caravan;
use App\Models\CaravanCastration;
use App\Models\CaravanDeath;
use App\Models\Company;
use App\Models\DeathCause;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderMale;
use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class CastrationCandidatesTest extends TestCase
{
    private Tenant $tenant;
    private Company $company;
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

    public function test_candidates_endpoint_filters_correctly(): void
    {
        // 1. Eligible calf
        $c1 = Caravan::create([
            'company_id' => $this->company->id,
            'batch_id' => $this->batch->id,
            'identification' => 'CAND-CALF-01',
            'sex' => 'M',
            'category_id' => $this->terneroCatId,
            'teeth' => 0,
        ]);

        // 2. Eligible torito
        $c2 = Caravan::create([
            'company_id' => $this->company->id,
            'batch_id' => $this->batch->id,
            'identification' => 'CAND-TORITO-01',
            'sex' => 'M',
            'category_id' => $this->toritoCatId,
            'teeth' => 2,
        ]);

        // 3. Torito in active service order
        $c3 = Caravan::create([
            'company_id' => $this->company->id,
            'batch_id' => $this->batch->id,
            'identification' => 'CAND-TORITO-SO',
            'sex' => 'M',
            'category_id' => $this->toritoCatId,
            'teeth' => 2,
        ]);

        $so = ServiceOrder::create([
            'company_id' => $this->company->id,
            'batch_id' => $this->batch->id,
            'code' => 'SO-BLOCKED-01',
            'status' => 'IN_SERVICE',
            'planned_start_date' => '2026-10-01',
        ]);

        ServiceOrderMale::create([
            'service_order_id' => $so->id,
            'male_caravan_id' => $c3->id,
            'status' => 'ACTIVE',
        ]);

        // 4. Ineligible: female
        Caravan::create([
            'company_id' => $this->company->id,
            'batch_id' => $this->batch->id,
            'identification' => 'FEMALE-01',
            'sex' => 'H',
            'category_id' => $this->terneroCatId,
            'teeth' => 0,
        ]);

        // 5. Ineligible: already castrated calf
        $c5 = Caravan::create([
            'company_id' => $this->company->id,
            'batch_id' => $this->batch->id,
            'identification' => 'ALREADY-CAST-01',
            'sex' => 'M',
            'category_id' => $this->terneroCatId,
            'teeth' => 0,
        ]);
        CaravanCastration::create([
            'company_id' => $this->company->id,
            'caravan_id' => $c5->id,
            'castrated_on' => '2026-09-01',
            'from_category_id' => $this->terneroCatId,
        ]);

        // 6. Ineligible: Novillito
        Caravan::create([
            'company_id' => $this->company->id,
            'batch_id' => $this->batch->id,
            'identification' => 'NOVILLITO-01',
            'sex' => 'M',
            'category_id' => $this->novillitoCatId,
            'teeth' => 2,
        ]);

        // 7. Ineligible: Toro
        Caravan::create([
            'company_id' => $this->company->id,
            'batch_id' => $this->batch->id,
            'identification' => 'TORO-01',
            'sex' => 'M',
            'category_id' => $this->toroCatId,
            'teeth' => 6,
        ]);

        $response = $this->withHeader('X-Company-ID', (string) $this->company->id)
            ->getJson("http://test.localhost/api/batches/{$this->batch->id}/castration-candidates");

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertCount(3, $data);

        $tags = array_column($data, 'caravan_number');
        $this->assertContains('CAND-CALF-01', $tags);
        $this->assertContains('CAND-TORITO-01', $tags);
        $this->assertContains('CAND-TORITO-SO', $tags);

        // Check blocked_by
        $soItem = collect($data)->firstWhere('caravan_number', 'CAND-TORITO-SO');
        $this->assertSame('SO-BLOCKED-01', $soItem['blocked_by']);

        $normalItem = collect($data)->firstWhere('caravan_number', 'CAND-TORITO-01');
        $this->assertNull($normalItem['blocked_by']);

        // Check age limit
        $this->assertIsInt($response->json('steer_age_limit_months'));
    }
}

