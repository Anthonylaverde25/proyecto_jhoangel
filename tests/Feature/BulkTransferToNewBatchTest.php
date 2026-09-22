<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Batch;
use App\Models\BatchType;
use App\Models\Caravan;
use App\Models\CaravanMovement;
use App\Models\Company;
use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Transferring caravans is what moves livestock between production stages: the batch
 * itself never changes its (activity, type) pair.
 */
class BulkTransferToNewBatchTest extends TestCase
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

    private function activityId(string $code): int
    {
        return (int) Activity::where('code', $code)->firstOrFail()->id;
    }

    private function batchTypeId(string $code): int
    {
        return (int) BatchType::where('code', $code)->firstOrFail()->id;
    }

    /**
     * @return array{0: Batch, 1: Caravan, 2: Caravan}
     */
    private function weaningBatchWithTwoCalves(): array
    {
        $batch = Batch::create([
            'company_id' => $this->company->id,
            'name' => 'Lote de Destete 2026',
            'activity_id' => $this->activityId('CRIA'),
            'batch_type_id' => $this->batchTypeId('WEANING'),
            'is_active' => true,
        ]);

        $heifer = Caravan::create([
            'company_id' => $this->company->id,
            'identification' => 'DST-H-01',
            'sex' => 'H',
            'teeth' => 0,
            'batch_id' => $batch->id,
        ]);

        $steer = Caravan::create([
            'company_id' => $this->company->id,
            'identification' => 'DST-M-01',
            'sex' => 'M',
            'teeth' => 0,
            'batch_id' => $batch->id,
        ]);

        return [$batch, $heifer, $steer];
    }

    public function test_transfer_records_origin_and_destination_batches(): void
    {
        [$source, $heifer, $steer] = $this->weaningBatchWithTwoCalves();

        $target = Batch::create([
            'company_id' => $this->company->id,
            'name' => 'Vaquillonas de Recría A',
            'activity_id' => $this->activityId('RECRIA'),
            'batch_type_id' => $this->batchTypeId('GROWING_HEIFERS'),
            'is_confined' => false,
            'is_active' => true,
        ]);

        $response = $this->postJson('http://test.localhost/api/caravans/bulk-transfer', [
            'caravan_ids' => [$heifer->id, $steer->id],
            'target_batch_id' => $target->id,
        ], $this->headers());

        $response->assertStatus(200);

        foreach ([$heifer, $steer] as $caravan) {
            $this->assertDatabaseHas('caravan_movements', [
                'caravan_id' => $caravan->id,
                'type' => 'TRANSFER',
                'from_batch_id' => $source->id,
                'to_batch_id' => $target->id,
            ]);
        }
    }

    public function test_transfer_creates_the_destination_batch_in_the_same_transaction(): void
    {
        [$source, $heifer] = $this->weaningBatchWithTwoCalves();

        $response = $this->postJson('http://test.localhost/api/caravans/bulk-transfer', [
            'caravan_ids' => [$heifer->id],
            'new_batch' => [
                'name' => 'Vientres de Reposición 2026',
                'activity_id' => $this->activityId('RECRIA'),
                'batch_type_id' => $this->batchTypeId('GROWING_REPLACEMENT_FEMALES'),
                'is_confined' => true,
            ],
        ], $this->headers());

        $response->assertStatus(200);
        $response->assertJsonPath('transferred_count', 1);

        $created = Batch::find($response->json('target_batch_id'));
        $this->assertNotNull($created);
        $this->assertSame('Vientres de Reposición 2026', $created->name);
        $this->assertTrue((bool) $created->is_confined);
        $this->assertSame($this->batchTypeId('GROWING_REPLACEMENT_FEMALES'), (int) $created->batch_type_id);
        $this->assertSame($created->id, $heifer->fresh()->batch_id);

        $this->assertDatabaseHas('caravan_movements', [
            'caravan_id' => $heifer->id,
            'from_batch_id' => $source->id,
            'to_batch_id' => $created->id,
        ]);
    }

    public function test_an_invalid_destination_batch_leaves_no_orphan_behind(): void
    {
        [$source, $heifer] = $this->weaningBatchWithTwoCalves();
        $batchesBefore = Batch::count();

        $response = $this->postJson('http://test.localhost/api/caravans/bulk-transfer', [
            'caravan_ids' => [$heifer->id],
            'new_batch' => [
                'name' => 'Vaquillonas mal clasificadas',
                'activity_id' => $this->activityId('CRIA'),
                'batch_type_id' => $this->batchTypeId('GROWING_HEIFERS'),
                'is_confined' => false,
            ],
        ], $this->headers());

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('new_batch.batch_type_id');

        $this->assertSame($batchesBefore, Batch::count());
        $this->assertSame($source->id, $heifer->fresh()->batch_id);
    }

    public function test_a_new_recria_batch_must_declare_its_management_system(): void
    {
        [, $heifer] = $this->weaningBatchWithTwoCalves();

        $this->postJson('http://test.localhost/api/caravans/bulk-transfer', [
            'caravan_ids' => [$heifer->id],
            'new_batch' => [
                'name' => 'Novillitos sin manejo declarado',
                'activity_id' => $this->activityId('RECRIA'),
                'batch_type_id' => $this->batchTypeId('GROWING_STEERS'),
            ],
        ], $this->headers())
            ->assertStatus(422)
            ->assertJsonValidationErrors('new_batch.is_confined');
    }

    public function test_a_weaning_batch_opens_into_two_recria_batches_and_is_left_empty(): void
    {
        [$source, $heifer, $steer] = $this->weaningBatchWithTwoCalves();

        $commercial = $this->postJson('http://test.localhost/api/caravans/bulk-transfer', [
            'caravan_ids' => [$heifer->id],
            'new_batch' => [
                'name' => 'Vaquillonas de Recría',
                'activity_id' => $this->activityId('RECRIA'),
                'batch_type_id' => $this->batchTypeId('GROWING_HEIFERS'),
                'is_confined' => false,
            ],
        ], $this->headers())->assertStatus(200);

        $replacement = $this->postJson('http://test.localhost/api/caravans/bulk-transfer', [
            'caravan_ids' => [$steer->id],
            'new_batch' => [
                'name' => 'Toritos de Reposición',
                'activity_id' => $this->activityId('RECRIA'),
                'batch_type_id' => $this->batchTypeId('GROWING_REPLACEMENT_BULLS'),
                'is_confined' => true,
            ],
        ], $this->headers())->assertStatus(200);

        // The source keeps its activity and type, and is simply empty now.
        $source->refresh();
        $this->assertSame($this->activityId('CRIA'), (int) $source->activity_id);
        $this->assertSame($this->batchTypeId('WEANING'), (int) $source->batch_type_id);
        $this->assertSame(0, Caravan::where('batch_id', $source->id)->count());

        // Emptied, not "never held animals": there are outgoing movements.
        $this->assertSame(2, CaravanMovement::where('from_batch_id', $source->id)->count());

        $this->assertNotSame(
            $commercial->json('target_batch_id'),
            $replacement->json('target_batch_id')
        );
    }
}
