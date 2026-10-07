<?php

declare(strict_types=1);

namespace Tests\Feature\Caravans;

use App\Core\Enums\AnimalSex;
use App\Models\Batch;
use App\Models\Caravan;
use App\Models\Company;
use App\Models\Farm;
use App\Models\Provider;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET /dashboard/herd-summary: the head count the phone shows on its home screen.
 */
class HerdSummaryTest extends TestCase
{
    private const URL = 'http://test.localhost/api/dashboard/herd-summary';

    private Tenant $tenant;
    private Company $company;
    private Company $otherCompany;
    private Batch $batch;

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

        $user = User::first() ?? User::create([
            'company_id' => $this->company->id,
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
        ]);
        Sanctum::actingAs($user, ['*']);

        $this->batch = Batch::create([
            'company_id' => $this->company->id,
            'name' => 'Rodeo Cría Sur',
            'is_active' => true,
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

    public function test_counts_the_company_own_herd(): void
    {
        $base = $this->total();
        $this->makeCaravan('032000000990001', $this->company->id, $this->batch->id);
        $this->makeCaravan('032000000990002', $this->company->id, $this->batch->id);
        $this->makeCaravan('032000000990003', $this->company->id, null);

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.total', $base + 3)
            ->assertJsonStructure(['data' => ['total', 'updated_at']]);
    }

    public function test_leaves_out_animals_still_in_a_provider_farm(): void
    {
        $provider = Provider::create(['name' => 'Proveedor', 'cuit' => '30-11111111-9', 'is_active' => true]);
        $farm = Farm::create([
            'company_id' => $this->company->id,
            'name' => 'Campo del proveedor',
            'renspa' => '11.11.1.11111/11',
            'provider_id' => $provider->id,
            'is_active' => true,
        ]);
        $providerBatch = Batch::create([
            'company_id' => $this->company->id,
            'farm_id' => $farm->id,
            'name' => 'Tropa en origen',
            'is_active' => true,
        ]);

        $base = $this->total();
        $this->makeCaravan('032000000990011', $this->company->id, $this->batch->id);
        $this->makeCaravan('032000000990012', $this->company->id, $providerBatch->id);

        $this->assertSame($base + 1, $this->total());
    }

    public function test_does_not_count_another_company_animals(): void
    {
        $base = $this->total();
        $this->makeCaravan('032000000990021', $this->company->id, $this->batch->id);
        $this->makeCaravan('032000000990022', $this->otherCompany->id, null);

        $this->assertSame($base + 1, $this->total());
    }

    public function test_a_company_without_animals_counts_zero(): void
    {
        // The tenant's seed fills the default company; the other one starts empty.
        $this->app->make(\App\Core\Interfaces\ICompanyContext::class)->setCompanyId($this->otherCompany->id);

        $this->assertSame(0, $this->total());
    }

    public function test_requires_authentication(): void
    {
        $this->app['auth']->forgetGuards();

        $this->getJson(self::URL)->assertStatus(401);
    }

    /** The tenant's migrations seed sample animals, so counts are taken relative to this. */
    private function total(): int
    {
        return (int) $this->getJson(self::URL)->assertOk()->json('data.total');
    }

    private function makeCaravan(string $identification, int $companyId, ?int $batchId): void
    {
        Caravan::withoutGlobalScopes()->create([
            'company_id' => $companyId,
            'batch_id' => $batchId,
            'renspa' => 'NO_DEFINIDO',
            'identification' => $identification,
            'teeth' => 0,
            'sex' => AnimalSex::FEMALE,
        ]);
    }
}
