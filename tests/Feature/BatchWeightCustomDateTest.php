<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Batch;
use App\Models\BatchType;
use App\Models\BatchWeight;
use App\Models\Caravan;
use App\Models\Company;
use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class BatchWeightCustomDateTest extends TestCase
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

    private function headers(): array
    {
        return ['X-Company-ID' => (string) $this->company->id];
    }

    public function test_upserting_caravan_with_entry_date_records_batch_weight_on_that_exact_date(): void
    {
        $batch = Batch::create([
            'company_id' => $this->company->id,
            'name' => 'Lote Fechas Test',
            'activity_id' => Activity::where('code', 'CRIA')->firstOrFail()->id,
            'batch_type_id' => BatchType::where('code', 'WEANING')->firstOrFail()->id,
            'is_active' => true,
        ]);

        $customDate = '2026-03-15';

        $response = $this->postJson('http://test.localhost/api/caravans', [
            'identification' => 'DATE-01',
            'sex' => 'M',
            'teeth' => 0,
            'entry_weight' => 195.0,
            'batch_id' => $batch->id,
            'entry_date' => $customDate,
        ], $this->headers());

        $response->assertStatus(201);

        $weight = BatchWeight::where('batch_id', $batch->id)->latest('id')->first();
        $this->assertNotNull($weight);
        $this->assertSame($customDate, $weight->weighing_date->format('Y-m-d'));
        $this->assertEqualsWithDelta(195.0, (float) $weight->weight, 0.01);
    }

    public function test_bulk_transfer_with_movement_date_records_movements_on_that_exact_date(): void
    {
        $origin = Batch::create([
            'company_id' => $this->company->id,
            'name' => 'Lote Origen Test',
            'activity_id' => Activity::where('code', 'CRIA')->firstOrFail()->id,
            'batch_type_id' => BatchType::where('code', 'WEANING')->firstOrFail()->id,
            'is_active' => true,
        ]);

        $caravan = Caravan::create([
            'company_id' => $this->company->id,
            'batch_id' => $origin->id,
            'identification' => 'DATE-02',
            'sex' => 'M',
            'teeth' => 0,
        ]);

        $caravan->weights()->create([
            'weight' => 210.0,
            'weighing_date' => '2026-02-01',
            'current' => true,
        ]);

        $destBatch = Batch::create([
            'company_id' => $this->company->id,
            'name' => 'Lote Destino Test',
            'activity_id' => Activity::where('code', 'RECRIA')->firstOrFail()->id,
            'batch_type_id' => BatchType::where('code', 'GROWING_STEERS')->firstOrFail()->id,
            'is_active' => true,
        ]);

        $transferDate = '2026-04-20';

        $response = $this->postJson('http://test.localhost/api/caravans/bulk-transfer', [
            'caravan_ids' => [$caravan->id],
            'target_batch_id' => $destBatch->id,
            'movement_date' => $transferDate,
        ], $this->headers());

        $response->assertStatus(200);

        // Origin batch should have MOVEMENT_OUT on 2026-04-20
        $originWeight = BatchWeight::where('batch_id', $origin->id)->latest('id')->first();
        $this->assertSame('MOVEMENT_OUT', $originWeight->type);
        $this->assertSame($transferDate, $originWeight->weighing_date->format('Y-m-d'));

        // Destination batch should have MOVEMENT_IN on 2026-04-20
        $destWeight = BatchWeight::where('batch_id', $destBatch->id)->latest('id')->first();
        $this->assertSame('MOVEMENT_IN', $destWeight->type);
        $this->assertSame($transferDate, $destWeight->weighing_date->format('Y-m-d'));
    }
}
