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

/**
 * The batch aggregate is a statistic over a set of animals: when the set changes, the
 * statistic moves without any animal having gained or lost a gram. These tests follow
 * the worked examples of section 3 of the implementation plan.
 */
class BatchWeightCompositionTest extends TestCase
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

    private function makeBatch(string $name, string $activityCode, string $typeCode): Batch
    {
        return Batch::create([
            'company_id' => $this->company->id,
            'name' => $name,
            'activity_id' => Activity::where('code', $activityCode)->firstOrFail()->id,
            'batch_type_id' => BatchType::where('code', $typeCode)->firstOrFail()->id,
            'is_active' => true,
        ]);
    }

    /** Creates a weighed animal in a batch. */
    private function makeCaravan(Batch $batch, string $id, float $weight, string $sex = 'H'): Caravan
    {
        $caravan = Caravan::create([
            'company_id' => $this->company->id,
            'batch_id' => $batch->id,
            'identification' => $id,
            'sex' => $sex,
            'teeth' => 0,
        ]);

        $caravan->weights()->create([
            'weight' => $weight,
            'weighing_date' => now()->toDateString(),
            'current' => true,
        ]);

        return $caravan;
    }

    /**
     * Reference herd of the plan: 80 head, half at 155 kg and half at 205 kg,
     * 14.400 kg in total, averaging 180 kg.
     *
     * @return array{0: Batch, 1: Caravan[], 2: Caravan[]} batch, light half, heavy half
     */
    private function referenceHerd(int $perHalf = 4): array
    {
        $batch = $this->makeBatch('Destete 2026', 'CRIA', 'WEANING');

        $light = [];
        $heavy = [];

        for ($i = 1; $i <= $perHalf; $i++) {
            $light[] = $this->makeCaravan($batch, "LIV-{$i}", 155.0);
            $heavy[] = $this->makeCaravan($batch, "PES-{$i}", 205.0, 'M');
        }

        return [$batch, $light, $heavy];
    }

    /** @return BatchWeight[] */
    private function series(int $batchId): array
    {
        return BatchWeight::where('batch_id', $batchId)
            ->orderBy('weighing_date')
            ->orderBy('id')
            ->get()
            ->all();
    }

    private function transfer(array $caravans, array $newBatch): array
    {
        $response = $this->postJson('http://test.localhost/api/caravans/bulk-transfer', [
            'caravan_ids' => array_map(fn (Caravan $c) => $c->id, $caravans),
            'new_batch' => $newBatch,
        ], $this->headers());

        $response->assertStatus(200);

        return $response->json();
    }

    // ---------------------------------------------------------------- 3.1

    public function test_moving_the_heavy_half_lowers_the_average_without_anyone_losing_weight(): void
    {
        [$origin, , $heavy] = $this->referenceHerd();

        $result = $this->transfer($heavy, [
            'name' => 'Novillitos 2026',
            'activity_id' => Activity::where('code', 'RECRIA')->firstOrFail()->id,
            'batch_type_id' => BatchType::where('code', 'GROWING_STEERS')->firstOrFail()->id,
            'is_confined' => false,
        ]);

        $originSeries = $this->series($origin->id);
        $out = end($originSeries);

        $this->assertSame('MOVEMENT_OUT', $out->type);
        $this->assertSame(4, $out->caravans_count);
        $this->assertSame(4, $out->weighed_count);
        $this->assertEqualsWithDelta(620.0, (float) $out->total_weight, 0.01);
        $this->assertEqualsWithDelta(155.0, (float) $out->weight, 0.01);

        $targetSeries = $this->series((int) $result['target_batch_id']);
        $in = end($targetSeries);

        $this->assertSame('MOVEMENT_IN', $in->type);
        $this->assertSame(4, $in->caravans_count);
        $this->assertEqualsWithDelta(820.0, (float) $in->total_weight, 0.01);
        $this->assertEqualsWithDelta(205.0, (float) $in->weight, 0.01);

        // Mass is conserved: the kilos missing from the origin are exactly the kilos
        // found in the destination. Nothing was lost, it was split.
        $this->assertEqualsWithDelta(
            1440.0,
            (float) $out->total_weight + (float) $in->total_weight,
            0.01
        );
    }

    public function test_the_closing_point_precedes_the_movement_on_the_same_date(): void
    {
        [$origin, , $heavy] = $this->referenceHerd();

        $this->transfer($heavy, [
            'name' => 'Novillitos 2026',
            'activity_id' => Activity::where('code', 'RECRIA')->firstOrFail()->id,
            'batch_type_id' => BatchType::where('code', 'GROWING_STEERS')->firstOrFail()->id,
            'is_confined' => false,
        ]);

        $series = $this->series($origin->id);
        $out = array_pop($series);
        $close = array_pop($series);

        $this->assertSame('CONTROL', $close->type, 'El cierre debe preceder al movimiento');
        $this->assertSame(8, $close->caravans_count, 'El cierre lleva la composición VIEJA');
        $this->assertEqualsWithDelta(1440.0, (float) $close->total_weight, 0.01);
        $this->assertEqualsWithDelta(180.0, (float) $close->weight, 0.01);

        // Both points share the date: the step is instantaneous, not spread over days.
        $this->assertSame(
            $close->weighing_date->format('Y-m-d'),
            $out->weighing_date->format('Y-m-d')
        );
        $this->assertLessThan($out->id, $close->id);
    }

    // ---------------------------------------------------------------- 3.2

    public function test_moving_the_light_half_raises_the_average_and_is_still_a_movement(): void
    {
        [$origin, $light] = $this->referenceHerd();

        $this->transfer($light, [
            'name' => 'Recría General',
            'activity_id' => Activity::where('code', 'RECRIA')->firstOrFail()->id,
            'batch_type_id' => BatchType::where('code', 'GROWING_MIXED')->firstOrFail()->id,
            'is_confined' => false,
        ]);

        $series = $this->series($origin->id);
        $out = end($series);

        // The compositional effect has no sign: here the average GOES UP, and nobody
        // gained a gram either.
        $this->assertSame('MOVEMENT_OUT', $out->type);
        $this->assertEqualsWithDelta(205.0, (float) $out->weight, 0.01);
        $this->assertEqualsWithDelta(820.0, (float) $out->total_weight, 0.01);
    }

    // ---------------------------------------------------------------- 3.3

    public function test_a_destination_holding_animals_also_gets_its_closing_point(): void
    {
        [, , $heavy] = $this->referenceHerd();

        $destination = $this->makeBatch('Vaquillonas de Recría', 'RECRIA', 'GROWING_HEIFERS');
        $this->makeCaravan($destination, 'VAQ-1', 225.0);
        $this->makeCaravan($destination, 'VAQ-2', 225.0);

        $this->postJson('http://test.localhost/api/caravans/bulk-transfer', [
            'caravan_ids' => array_map(fn (Caravan $c) => $c->id, $heavy),
            'target_batch_id' => $destination->id,
        ], $this->headers())->assertStatus(200);

        $series = $this->series($destination->id);
        $in = array_pop($series);
        $close = array_pop($series);

        $this->assertSame('MOVEMENT_IN', $in->type);
        $this->assertSame(6, $in->caravans_count);

        // The closing point exists even though it repeats the values the batch already
        // had: its job is to anchor the old composition on the date of the movement.
        $this->assertSame('CONTROL', $close->type);
        $this->assertSame(2, $close->caravans_count);
        $this->assertEqualsWithDelta(450.0, (float) $close->total_weight, 0.01);

        // 450 + 820 = 1.270 kg over 6 head
        $this->assertEqualsWithDelta(1270.0, (float) $in->total_weight, 0.01);
        $this->assertEqualsWithDelta(1270.0 / 6, (float) $in->weight, 0.01);
    }

    public function test_a_second_transfer_the_same_day_does_not_duplicate_the_closing_point(): void
    {
        [$origin, $light, $heavy] = $this->referenceHerd();

        $recria = Activity::where('code', 'RECRIA')->firstOrFail()->id;

        $this->transfer([$heavy[0]], [
            'name' => 'Novillitos A',
            'activity_id' => $recria,
            'batch_type_id' => BatchType::where('code', 'GROWING_STEERS')->firstOrFail()->id,
            'is_confined' => false,
        ]);

        $afterFirst = count($this->series($origin->id));

        $this->transfer([$heavy[1]], [
            'name' => 'Novillitos B',
            'activity_id' => $recria,
            'batch_type_id' => BatchType::where('code', 'GROWING_STEERS')->firstOrFail()->id,
            'is_confined' => false,
        ]);

        // Only the MOVEMENT_OUT of the second transfer is added: the closing point would
        // duplicate the row the first transfer already wrote today.
        $this->assertSame($afterFirst + 1, count($this->series($origin->id)));
    }

    // ---------------------------------------------------------------- 3.5

    public function test_an_emptied_batch_has_zero_kilos_and_an_undefined_average(): void
    {
        [$origin, $light, $heavy] = $this->referenceHerd();

        $this->transfer([...$light, ...$heavy], [
            'name' => 'Recría General',
            'activity_id' => Activity::where('code', 'RECRIA')->firstOrFail()->id,
            'batch_type_id' => BatchType::where('code', 'GROWING_MIXED')->firstOrFail()->id,
            'is_confined' => false,
        ]);

        $series = $this->series($origin->id);
        $out = end($series);

        $this->assertSame('MOVEMENT_OUT', $out->type);
        $this->assertSame(0, $out->caravans_count);
        // Zero kilos is a true fact: the pen holds no cattle.
        $this->assertEqualsWithDelta(0.0, (float) $out->total_weight, 0.01);
        // The average of an empty set, on the other hand, does not exist.
        $this->assertNull($out->weight);

        $origin->refresh();
        $this->assertNull($origin->current_weight);
        $this->assertSame(0, $origin->caravans_count);
    }

    public function test_an_emptied_batch_can_be_reused_and_opens_a_new_stretch(): void
    {
        [$origin, $light, $heavy] = $this->referenceHerd();

        $this->transfer([...$light, ...$heavy], [
            'name' => 'Recría General',
            'activity_id' => Activity::where('code', 'RECRIA')->firstOrFail()->id,
            'batch_type_id' => BatchType::where('code', 'GROWING_MIXED')->firstOrFail()->id,
            'is_confined' => false,
        ]);

        $newcomer = $this->makeCaravan($origin, 'NUEVO-1', 200.0);

        $this->postJson('http://test.localhost/api/caravans/bulk-transfer', [
            'caravan_ids' => [$newcomer->id],
            'target_batch_id' => $origin->id,
        ], $this->headers())->assertStatus(200);

        $series = $this->series($origin->id);
        $in = end($series);

        $this->assertSame('MOVEMENT_IN', $in->type);
        $this->assertSame(1, $in->caravans_count);
        $this->assertEqualsWithDelta(200.0, (float) $in->weight, 0.01);
    }

    // ---------------------------------------------------------------- 3.6

    public function test_a_partial_weighing_reports_the_measured_mass_and_its_coverage(): void
    {
        $batch = $this->makeBatch('Recría parcial', 'RECRIA', 'GROWING_MIXED');

        $this->makeCaravan($batch, 'PES-1', 190.0);
        $this->makeCaravan($batch, 'PES-2', 190.0);

        // Two more head with no current weight at all.
        Caravan::create([
            'company_id' => $this->company->id,
            'batch_id' => $batch->id,
            'identification' => 'SIN-1',
            'sex' => 'H',
            'teeth' => 0,
        ]);
        Caravan::create([
            'company_id' => $this->company->id,
            'batch_id' => $batch->id,
            'identification' => 'SIN-2',
            'sex' => 'H',
            'teeth' => 0,
        ]);

        app(\App\Core\Services\BatchWeightService::class)
            ->recalculateBatchWeight($batch->id, \App\Core\Enums\BatchWeightCause::CONTROL);

        $series = $this->series($batch->id);
        $row = end($series);

        $this->assertSame(4, $row->caravans_count);
        $this->assertSame(2, $row->weighed_count, 'El promedio se calculó sobre dos animales');
        // Measured mass, not an estimate of the whole batch: 2 x 190, never 4 x 190.
        $this->assertEqualsWithDelta(380.0, (float) $row->total_weight, 0.01);
        $this->assertEqualsWithDelta(190.0, (float) $row->weight, 0.01);
    }

    // ---------------------------------------------------------------- 3.7 y varios

    public function test_a_genuine_weighing_is_recorded_as_control(): void
    {
        $batch = $this->makeBatch('Recría', 'RECRIA', 'GROWING_MIXED');
        $caravan = $this->makeCaravan($batch, 'CAR-1', 180.0);

        $this->postJson("http://test.localhost/api/caravans/{$caravan->id}/weights", [
            'weight' => 195.0,
            'weighing_date' => now()->toDateString(),
        ], $this->headers())->assertSuccessful();

        $series = $this->series($batch->id);
        $last = end($series);

        $this->assertSame('CONTROL', $last->type);
        $this->assertSame(1, $last->caravans_count);
        $this->assertEqualsWithDelta(195.0, (float) $last->weight, 0.01);
    }

    public function test_a_batch_created_without_a_declared_weight_opens_with_no_average(): void
    {
        $response = $this->postJson('http://test.localhost/api/batches', [
            'name' => 'Lote sin peso declarado',
            'activity_id' => Activity::where('code', 'CRIA')->firstOrFail()->id,
            'batch_type_id' => BatchType::where('code', 'OPERATIONAL')->firstOrFail()->id,
            'is_confined' => false,
        ], $this->headers());

        $response->assertStatus(201);

        $series = $this->series((int) $response->json('id'));
        $this->assertCount(1, $series);
        $this->assertSame('INITIAL', $series[0]->type);
        $this->assertNull($series[0]->weight, 'No se fabrica un 0,00 kg');
        $this->assertSame(0, $series[0]->caravans_count);
    }

    public function test_a_batch_created_with_a_declared_weight_keeps_it(): void
    {
        $response = $this->postJson('http://test.localhost/api/batches', [
            'name' => 'Lote con peso declarado',
            'activity_id' => Activity::where('code', 'CRIA')->firstOrFail()->id,
            'batch_type_id' => BatchType::where('code', 'OPERATIONAL')->firstOrFail()->id,
            'weight' => 220.0,
            'is_confined' => false,
        ], $this->headers());

        $response->assertStatus(201);

        $series = $this->series((int) $response->json('id'));
        $this->assertEqualsWithDelta(220.0, (float) $series[0]->weight, 0.01);
    }

    public function test_the_series_records_how_recent_the_underlying_weighings_are(): void
    {
        $batch = $this->makeBatch('Recría', 'RECRIA', 'GROWING_MIXED');
        $this->makeCaravan($batch, 'CAR-1', 180.0);

        app(\App\Core\Services\BatchWeightService::class)
            ->recalculateBatchWeight($batch->id, \App\Core\Enums\BatchWeightCause::CONTROL);

        $series = $this->series($batch->id);
        $last = end($series);

        $this->assertNotNull($last->weights_as_of);
        $this->assertSame(now()->toDateString(), $last->weights_as_of->format('Y-m-d'));
    }
}
