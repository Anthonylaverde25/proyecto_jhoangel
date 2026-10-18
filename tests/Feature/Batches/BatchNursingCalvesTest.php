<?php

declare(strict_types=1);

namespace Tests\Feature\Batches;

use App\Core\Enums\WeighingTypeCode;
use App\Core\Interfaces\IBatchRepository;
use App\Models\Batch;
use App\Models\Caravan;
use App\Models\CaravanLineage;
use App\Models\Company;
use App\Models\Tenant;
use App\Models\WeighingType;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Calves at foot are born into their mother's batch: the batch read says how many of its head and
 * of its weighed kilos are theirs, so the stock views can show the adults apart.
 */
class BatchNursingCalvesTest extends TestCase
{
    private Tenant $tenant;
    private Company $company;

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

    private function caravan(Batch $batch, string $identification, ?float $weight, ?bool $nursing = null, ?Caravan $mother = null): Caravan
    {
        $caravan = Caravan::create([
            'company_id' => $this->company->id,
            'batch_id' => $batch->id,
            'identification' => $identification,
            'sex' => 'H',
            'teeth' => 0,
        ]);

        if ($weight !== null) {
            $caravan->weights()->create([
                'weighing_type_id' => WeighingType::idOf(WeighingTypeCode::CONTROL),
                'weight' => $weight,
                'weighing_date' => now()->toDateString(),
                'current' => true,
            ]);
        }

        if ($nursing !== null) {
            CaravanLineage::create(['caravan_id' => $caravan->id, 'mother_id' => $mother?->id, 'birth_date' => now()->toDateString(), 'is_nursing' => $nursing]);
        }

        return $caravan;
    }

    public function test_the_batch_read_counts_the_calves_at_foot_apart(): void
    {
        $batch = Batch::create(['company_id' => $this->company->id, 'name' => 'Cría con terneros', 'is_active' => true]);

        $cow = $this->caravan($batch, 'VACA-1', 450.0);
        $this->caravan($batch, 'VACA-2', 430.0);
        $this->caravan($batch, 'TERN-1', 35.5, true, $cow);
        $this->caravan($batch, 'TERN-2', null, true, $cow);
        // Weaned: it was a calf, it is no longer at foot.
        $this->caravan($batch, 'DEST-1', 180.0, false, $cow);

        $entity = app(IBatchRepository::class)->findById($batch->id);

        $this->assertSame(2, $entity->getNursingCount());
        $this->assertSame(1, $entity->getNursingWeighedCount(), 'TERN-2 has no weight yet');
        $this->assertEqualsWithDelta(35.5, $entity->getNursingTotalWeight(), 0.001);
    }

    public function test_a_batch_without_calves_reads_zero_not_null(): void
    {
        $batch = Batch::create(['company_id' => $this->company->id, 'name' => 'Recría', 'is_active' => true]);
        $this->caravan($batch, 'NOV-1', 300.0);

        $entity = app(IBatchRepository::class)->findById($batch->id);

        $this->assertSame(0, $entity->getNursingCount());
        $this->assertSame(0, $entity->getNursingWeighedCount());
        $this->assertSame(0.0, $entity->getNursingTotalWeight());
    }
}
