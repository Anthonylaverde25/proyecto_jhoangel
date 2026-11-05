<?php

declare(strict_types=1);

namespace Tests\Feature\ServiceOrders;

use App\Core\Enums\BatchWeightCause;
use App\Core\Enums\WeighingTypeCode;
use App\Core\Services\BatchWeightService;
use App\Models\Activity;
use App\Models\Batch;
use App\Models\BatchWeight;
use App\Models\Caravan;
use App\Models\CaravanMovement;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderMale;
use App\Models\WeighingType;
use Tests\Feature\Veterinary\VeterinaryTestCase;

/**
 * A bull of a running service is physically in the batch (5 cows + 1 bull = 6 head) but passing
 * through: the weights and the composition of the batch keep describing its herd.
 *
 * ANTHONY-TORO-TEST-004..006 are seeded APT.
 */
class BullsOnServiceBatchFiguresTest extends VeterinaryTestCase
{
    private Batch $rodeo;
    private Batch $bullPaddock;

    protected function setUp(): void
    {
        parent::setUp();

        $cria = Activity::updateOrCreate(['code' => 'CRIA'], ['name' => 'Cría']);
        $this->rodeo = $this->batch('Rodeo Figuras', $cria->id);
        $this->bullPaddock = $this->batch('Torada Figuras', $cria->id);
    }

    public function test_a_bull_joining_a_service_counts_as_stock_and_measures_nothing(): void
    {
        $bull = $this->herdWithBull();
        $pointsBefore = $this->rodeoPoints();

        $this->start([$bull->id])->assertStatus(201);

        $batch = $this->apiAs('GET', "/batches/{$this->rodeo->id}")->assertOk()->json();
        $batch = $batch['data'] ?? $batch;

        $this->assertSame(3, $batch['caravans_count']);
        $this->assertSame(3, $batch['head_count']);
        $this->assertSame(1, $batch['bulls_on_service_count']);
        $this->assertSame(1, $batch['bulls_on_service_weighed_count']);
        $this->assertEqualsWithDelta(800.0, $batch['bulls_on_service_total_weight'], 0.01);
        $this->assertSame(2, $batch['weighed_count']);
        $this->assertEqualsWithDelta(900.0, $batch['total_weight'], 0.01);
        $this->assertEqualsWithDelta(450.0, $batch['current_weight'], 0.01);
        $this->assertEqualsWithDelta(500.0, $batch['max_weight'], 0.01);
        $this->assertSame(1, $batch['weighed_category_count']);

        // The herd did not change: the curve has nothing to say.
        $this->assertSame($pointsBefore, $this->rodeoPoints());
    }

    public function test_the_overview_shows_the_herd_and_names_the_bull_apart(): void
    {
        $bull = $this->herdWithBull();
        $orderId = (int) $this->start([$bull->id])->assertStatus(201)->json('id');

        $overview = $this->apiAs('GET', "/batches/{$this->rodeo->id}/overview")->assertOk()->json();
        $overview = $overview['data'] ?? $overview;

        $this->assertSame(['M' => 0, 'H' => 2], $overview['sex_composition']);
        $this->assertSame(2, array_sum(array_column($overview['category_composition'], 'count')));
        $this->assertCount(1, $overview['bulls_on_service']);
        $this->assertSame($bull->id, $overview['bulls_on_service'][0]['caravan_id']);
        $this->assertSame($orderId, $overview['bulls_on_service'][0]['order_id']);
        $this->assertSame($this->bullPaddock->id, $overview['bulls_on_service'][0]['origin_batch_id']);
        $this->assertSame('Torada Figuras', $overview['bulls_on_service'][0]['origin_batch_name']);
        $this->assertEqualsWithDelta(800.0, $overview['bulls_on_service'][0]['weight'], 0.01);
        $this->assertSame([], array_values(array_filter($overview['alerts'], fn ($alert) => $alert['type'] === 'UNWEIGHED')));
    }

    public function test_the_caravan_list_marks_only_the_bull_on_service(): void
    {
        $bull = $this->herdWithBull();
        $this->start([$bull->id])->assertStatus(201);

        $rows = $this->apiAs('GET', "/caravans/summary?batch_id={$this->rodeo->id}&per_page=10")->assertOk()->json('data');
        $codes = array_column($rows, 'service_order_code_on_stay', 'id');

        $this->assertCount(3, $rows);
        $this->assertNotNull($codes[$bull->id]);
        $this->assertCount(1, array_filter($codes));
    }

    public function test_withdrawing_the_bull_leaves_the_herd_figures_and_its_curve_untouched(): void
    {
        $bull = $this->herdWithBull();
        $orderId = (int) $this->start([$bull->id])->assertStatus(201)->json('id');
        $pointsBefore = $this->rodeoPoints();

        $this->apiAs('POST', "/service-orders/{$orderId}/close-service", [
            'withdrawal_date' => '2026-12-30',
            'default_destination_batch_id' => $this->bullPaddock->id,
        ])->assertStatus(200);

        $rodeo = $this->rodeo->fresh();
        $this->assertSame(2, (int) $rodeo->caravans_count);
        $this->assertEqualsWithDelta(450.0, (float) $rodeo->current_weight, 0.01);
        $this->assertSame($pointsBefore, $this->rodeoPoints());

        // The batch he goes back to does measure him: he is its herd.
        $paddock = $this->bullPaddock->fresh();
        $this->assertSame(1, (int) $paddock->weighed_count);
        $this->assertEqualsWithDelta(800.0, (float) $paddock->total_weight, 0.01);
    }

    public function test_an_order_that_is_not_running_does_not_hide_a_bull(): void
    {
        $this->cow('FIG-V-P1', 400);
        $bull = $this->bullIn('ANTHONY-TORO-TEST-005', 800);
        $bull->update(['batch_id' => $this->rodeo->id]);

        foreach (['PLANNED', 'CANCELLED'] as $index => $status) {
            $order = ServiceOrder::create([
                'company_id' => $this->company->id,
                'batch_id' => $this->rodeo->id,
                'code' => "SO-FIG-{$index}",
                'status' => $status,
                'service_type' => 'single',
                'planned_start_date' => '2026-12-01',
            ]);
            ServiceOrderMale::create([
                'company_id' => $this->company->id,
                'service_order_id' => $order->id,
                'male_caravan_id' => $bull->id,
                'status' => 'ACTIVE',
            ]);
        }

        app(BatchWeightService::class)->recalculateBatchWeight($this->rodeo->id, BatchWeightCause::CONTROL);

        $rodeo = $this->rodeo->fresh();
        $this->assertSame(2, (int) $rodeo->weighed_count);
        $this->assertEqualsWithDelta(1200.0, (float) $rodeo->total_weight, 0.01);
    }

    public function test_a_movement_of_the_herd_still_opens_a_point(): void
    {
        $bull = $this->herdWithBull();
        $this->start([$bull->id])->assertStatus(201);
        $pointsBefore = $this->rodeoPoints();

        $this->cow('FIG-V-03', 300);
        app(BatchWeightService::class)->recalculateBatchWeight($this->rodeo->id, BatchWeightCause::MOVEMENT_IN);

        $this->assertSame($pointsBefore + 1, $this->rodeoPoints());
        $point = BatchWeight::where('batch_id', $this->rodeo->id)->orderByDesc('id')->firstOrFail();
        $this->assertSame(3, (int) $point->caravans_count);
        $this->assertEqualsWithDelta(1200.0, (float) $point->total_weight, 0.01);
        $this->assertSame(4, (int) $this->rodeo->fresh()->caravans_count);
    }

    public function test_the_migration_cleans_the_curve_of_a_running_service(): void
    {
        $bull = $this->herdWithBull();
        $this->start([$bull->id])->assertStatus(201);

        // What the old rule left: a step when the bull entered, and the batch measuring him.
        BatchWeight::where('batch_id', $this->rodeo->id)->delete();
        $arrival = CaravanMovement::where('caravan_id', $bull->id)->where('to_batch_id', $this->rodeo->id)->firstOrFail()->created_at;
        $write = function (string $type, int $head, float $total, string $date, $createdAt): int {
            $point = BatchWeight::create([
                'batch_id' => $this->rodeo->id,
                'weight' => $total / $head,
                'caravans_count' => $head,
                'weighed_count' => $head,
                'total_weight' => $total,
                'type' => $type,
                'weighing_date' => $date,
            ]);
            \DB::table('batch_weights')->where('id', $point->id)->update(['created_at' => $createdAt]);

            return (int) $point->id;
        };
        $before = $write('CONTROL', 2, 900.0, '2026-09-01', $arrival->copy()->subDay());
        $step = $write('MOVEMENT_IN', 3, 1700.0, '2026-10-01', $arrival);
        $after = $write('CONTROL', 3, 1720.0, '2026-10-20', $arrival->copy()->addDay());
        \DB::table('batches')->where('id', $this->rodeo->id)->update(['total_weight' => 1700, 'weighed_count' => 3, 'current_weight' => 566.67, 'max_weight' => 800]);

        (require database_path('migrations/tenant/2026_11_05_000001_take_bulls_on_service_out_of_batch_weight_series.php'))->up();

        $this->assertDatabaseHas('batch_weights', ['id' => $before, 'caravans_count' => 2]);
        $this->assertDatabaseMissing('batch_weights', ['id' => $step]);
        $point = BatchWeight::findOrFail($after);
        $this->assertSame(2, (int) $point->caravans_count);
        $this->assertEqualsWithDelta(920.0, (float) $point->total_weight, 0.01);
        $this->assertEqualsWithDelta(460.0, (float) $point->weight, 0.01);

        $rodeo = $this->rodeo->fresh();
        $this->assertSame(3, (int) $rodeo->caravans_count);
        $this->assertSame(2, (int) $rodeo->weighed_count);
        $this->assertEqualsWithDelta(450.0, (float) $rodeo->current_weight, 0.01);
        $this->assertEqualsWithDelta(500.0, (float) $rodeo->max_weight, 0.01);
    }

    /** Two weighed cows (400 and 500 kg) with a first point on their curve, and a weighed bull in the paddock. */
    private function herdWithBull(): Caravan
    {
        $this->cow('FIG-V-01', 400);
        $this->cow('FIG-V-02', 500);
        app(BatchWeightService::class)->recalculateBatchWeight($this->rodeo->id, BatchWeightCause::CONTROL);

        $bull = $this->bullIn('ANTHONY-TORO-TEST-004', 800);
        app(BatchWeightService::class)->recalculateBatchWeight($this->bullPaddock->id, BatchWeightCause::CONTROL);

        return $bull;
    }

    private function rodeoPoints(): int
    {
        return BatchWeight::where('batch_id', $this->rodeo->id)->count();
    }

    private function start(array $bullIds)
    {
        return $this->apiAs('POST', "/batches/{$this->rodeo->id}/start-service", [
            'male_caravan_ids' => $bullIds,
            'planned_start_date' => '2026-11-01',
            'service_type' => count($bullIds) > 1 ? 'multi' : 'single',
            'is_controlled_service' => false,
        ]);
    }

    private function batch(string $name, int $activityId): Batch
    {
        return Batch::create([
            'company_id' => $this->company->id,
            'name' => $name,
            'is_active' => true,
            'activity_id' => $activityId,
        ]);
    }

    private function cow(string $tag, float $weight): Caravan
    {
        $cow = Caravan::create([
            'company_id' => $this->company->id,
            'batch_id' => $this->rodeo->id,
            'identification' => $tag,
            'sex' => 'H',
        ]);
        $this->weigh($cow, $weight);

        return $cow;
    }

    private function bullIn(string $tag, float $weight): Caravan
    {
        $bull = Caravan::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('identification', $tag)
            ->firstOrFail();
        $bull->update(['batch_id' => $this->bullPaddock->id]);
        $bull->weights()->update(['current' => false]);
        $this->weigh($bull, $weight);

        return $bull;
    }

    private function weigh(Caravan $caravan, float $weight): void
    {
        $caravan->weights()->create([
            'weighing_type_id' => WeighingType::idOf(WeighingTypeCode::CONTROL),
            'weight' => $weight,
            'weighing_date' => '2026-10-01',
            'current' => true,
        ]);
    }
}
