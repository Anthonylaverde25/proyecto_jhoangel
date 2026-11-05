<?php

declare(strict_types=1);

namespace Tests\Feature\Castrations;

use App\Core\Exceptions\ServiceOrderDomainException;
use App\Core\Services\BullServiceFitnessPolicy;
use App\Models\AnimalCategory;
use App\Models\Batch;
use App\Models\Caravan;
use App\Models\Company;
use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class CastrationServiceFitnessTest extends TestCase
{
    private Tenant $tenant;
    private Company $company;
    private Batch $batch;
    private int $novillitoCatId;

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
        $this->novillitoCatId = (int) AnimalCategory::where('code', 'NOVILLITO')->value('id');
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        parent::tearDown();
    }

    public function test_castrated_male_reclassified_to_novillito_is_rejected_as_service_reproducer(): void
    {
        $steer = Caravan::create([
            'company_id' => $this->company->id,
            'batch_id' => $this->batch->id,
            'identification' => 'STEER-FIT-01',
            'sex' => 'M',
            'category_id' => $this->novillitoCatId,
            'teeth' => 2,
        ]);

        $policy = app(BullServiceFitnessPolicy::class);

        $this->expectException(ServiceOrderDomainException::class);
        $this->expectExceptionMessageMatches('/sólo una categoría reproductiva/i');

        $policy->assertFit((int) $steer->id, (int) $this->company->id, 'STEER-FIT-01');
    }
}

