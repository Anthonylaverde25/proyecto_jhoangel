<?php

declare(strict_types=1);

namespace Tests\Feature\Weighing;

use App\Core\Enums\WeighingTypeCode;
use App\Models\Activity;
use App\Models\AnimalCategory;
use App\Models\Batch;
use App\Models\BatchType;
use App\Models\BatchWeight;
use App\Models\Caravan;
use App\Models\CaravanWeight;
use App\Models\Company;
use App\Models\Tenant;
use App\Models\WeighingOrder;
use App\Models\WeighingOrderCategoryAverage;
use App\Models\WeighingOrderGroup;
use App\Models\WeighingType;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * A batch weighing stays on a registered order and each caravan covered receives its average. A
 * cage weighing averages the whole batch; a general average weighing, each category on its own.
 */
class BatchWeighingTest extends TestCase
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

    /** @return array{0: Batch, 1: Caravan[]} */
    private function batchWith(int $heads, float $previousWeight = 300.0): array
    {
        $batch = Batch::create([
            'company_id' => $this->company->id,
            'name' => 'Lote Jaula ' . uniqid(),
            'activity_id' => Activity::where('code', 'RECRIA')->firstOrFail()->id,
            'batch_type_id' => BatchType::where('code', 'GROWING_STEERS')->firstOrFail()->id,
            'is_active' => true,
        ]);

        $caravans = [];
        for ($i = 1; $i <= $heads; $i++) {
            $caravan = Caravan::create([
                'company_id' => $this->company->id,
                'batch_id' => $batch->id,
                'identification' => 'JAULA-' . uniqid() . "-{$i}",
                'sex' => 'M',
                'teeth' => 0,
            ]);

            CaravanWeight::create([
                'weighing_type_id' => WeighingType::idOf(WeighingTypeCode::CONTROL),
                'caravan_id' => $caravan->id,
                'weight' => $previousWeight + $i * 10,
                'weighing_date' => '2026-08-01',
                'current' => true,
            ]);

            $caravans[] = $caravan;
        }

        return [$batch, $caravans];
    }

    private function payload(Batch $batch, array $groups, array $extra = []): array
    {
        return [
            'batch_id' => $batch->id,
            'weighing_type' => 'BATCH_CONTROL',
            'weighing_date' => '2026-10-01',
            'entry' => 'LOADS',
            'groups' => $groups,
            ...$extra,
        ];
    }

    public function test_a_cage_weighing_imputes_the_average_and_conserves_the_mass(): void
    {
        [$batch, $caravans] = $this->batchWith(3);

        $response = $this->postJson('http://test.localhost/api/weighing-orders/register-batch', $this->payload($batch, [
            ['head_count' => 2, 'gross_weight' => 900, 'tare_weight' => 20],
            ['head_count' => 1, 'gross_weight' => 400],
        ]), $this->headers())->assertStatus(201);

        $response->assertJsonPath('kind', 'REGISTERED')
            ->assertJsonPath('status', 'EXECUTED')
            ->assertJsonPath('mode', 'GROUP')
            ->assertJsonPath('total_heads', 3)
            ->assertJsonPath('weighed_count', 3);
        $this->assertEqualsWithDelta(1280.0, $response->json('total_net_weight'), 0.01);
        $this->assertEqualsWithDelta(426.67, $response->json('average_weight'), 0.01);
        $this->assertMatchesRegularExpression('/^PS-\d{8}-0001$/', $response->json('code'));

        $order = WeighingOrder::firstOrFail();
        foreach ($caravans as $caravan) {
            $current = CaravanWeight::where('caravan_id', $caravan->id)->where('current', true)->firstOrFail();
            $this->assertEqualsWithDelta(426.67, (float) $current->weight, 0.01);
            $this->assertSame('AVERAGE', $current->method);
            $this->assertSame('WEIGHING_ORDER', $current->source);
            $this->assertSame($order->id, $current->weighing_order_id);
            $this->assertSame(WeighingType::idOf(WeighingTypeCode::BATCH_CONTROL), $current->weighing_type_id);
        }

        $point = BatchWeight::where('batch_id', $batch->id)->latest('id')->firstOrFail();
        $this->assertSame('CONTROL', $point->type);
        $this->assertSame('2026-10-01', $point->weighing_date->format('Y-m-d'));
        $this->assertSame($order->id, $point->weighing_order_id);
        $this->assertSame(3, $point->weighed_count);
        $this->assertEqualsWithDelta(1280.0, (float) $point->total_weight, 0.05);

        $this->getJson("http://test.localhost/api/batches/{$batch->id}/weighing-orders", $this->headers())
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.entry', 'LOADS')
            ->assertJsonPath('0.groups.0.average_weight', 440);
    }

    /** @return array{0: Batch, 1: array<string, Caravan[]>} two cows and one calf, the three already weighed */
    private function mixedBatch(): array
    {
        [$batch, $caravans] = $this->batchWith(3);
        $category = static fn (string $code): int => (int) AnimalCategory::where('code', $code)->value('id');

        $caravans[0]->update(['category_id' => $category('VACA')]);
        $caravans[1]->update(['category_id' => $category('VACA')]);
        $caravans[2]->update(['category_id' => $category('TERNERO')]);

        return [$batch, ['VACA' => [$caravans[0], $caravans[1]], 'TERNERO' => [$caravans[2]]]];
    }

    private function averagesPayload(Batch $batch, array $averages): array
    {
        return [
            'batch_id' => $batch->id,
            'weighing_type' => 'BATCH_AVERAGE',
            'weighing_date' => '2026-10-01',
            'entry' => 'BY_CATEGORY',
            'averages' => $averages,
        ];
    }

    private function currentWeight(Caravan $caravan): CaravanWeight
    {
        return CaravanWeight::where('caravan_id', $caravan->id)->where('current', true)->firstOrFail();
    }

    public function test_each_category_receives_its_own_average_in_one_order(): void
    {
        [$batch, $caravans] = $this->mixedBatch();
        $vaca = $caravans['VACA'][0]->category_id;
        $ternero = $caravans['TERNERO'][0]->category_id;

        $response = $this->postJson('http://test.localhost/api/weighing-orders/register-batch', $this->averagesPayload($batch, [
            ['category_id' => $vaca, 'head_count' => 2, 'average_weight' => 450],
            ['category_id' => $ternero, 'head_count' => 1, 'average_weight' => 120],
        ]), $this->headers())->assertStatus(201);

        $response->assertJsonPath('entry', 'BY_CATEGORY')
            ->assertJsonPath('groups', [])
            ->assertJsonPath('total_heads', 3)
            ->assertJsonPath('weighed_count', 3)
            ->assertJsonPath('category_averages.0.category_name', 'Vaca Adulta');
        $this->assertEqualsWithDelta(1020.0, $response->json('total_net_weight'), 0.01);

        $this->assertSame(1, WeighingOrder::count());
        $this->assertSame(2, WeighingOrderCategoryAverage::count());
        $this->assertSame(0, WeighingOrderGroup::count());

        $this->assertEqualsWithDelta(450.0, (float) $this->currentWeight($caravans['VACA'][0])->weight, 0.001);
        $this->assertEqualsWithDelta(450.0, (float) $this->currentWeight($caravans['VACA'][1])->weight, 0.001);
        $this->assertEqualsWithDelta(120.0, (float) $this->currentWeight($caravans['TERNERO'][0])->weight, 0.001);
        $this->assertSame('AVERAGE', $this->currentWeight($caravans['TERNERO'][0])->method);

        $this->assertEqualsWithDelta(1020.0, (float) $batch->fresh()->total_weight, 0.01);
        $this->assertSame(1, BatchWeight::where('batch_id', $batch->id)->where('weighing_type_id', WeighingType::idOf(WeighingTypeCode::BATCH_AVERAGE))->count());
    }

    public function test_a_category_left_out_keeps_its_weight_and_stays_off_the_order(): void
    {
        [$batch, $caravans] = $this->mixedBatch();
        $calf = $caravans['TERNERO'][0];
        $before = (float) $this->currentWeight($calf)->weight;

        $response = $this->postJson('http://test.localhost/api/weighing-orders/register-batch', $this->averagesPayload($batch, [
            ['category_id' => $caravans['VACA'][0]->category_id, 'head_count' => 2, 'average_weight' => 450],
        ]), $this->headers())->assertStatus(201);

        $response->assertJsonPath('total_heads', 2)->assertJsonPath('planned_head_count', 2);
        $this->assertEqualsWithDelta($before, (float) $this->currentWeight($calf)->weight, 0.001);
        $this->assertSame(1, CaravanWeight::where('caravan_id', $calf->id)->count());
    }

    public function test_animals_without_a_category_are_weighed_as_their_own_group(): void
    {
        [$batch, $caravans] = $this->batchWith(2);

        $this->postJson('http://test.localhost/api/weighing-orders/register-batch', $this->averagesPayload($batch, [
            ['category_id' => null, 'head_count' => 2, 'average_weight' => 380],
        ]), $this->headers())->assertStatus(201)->assertJsonPath('category_averages.0.category_id', null);

        $this->assertEqualsWithDelta(380.0, (float) $this->currentWeight($caravans[0])->weight, 0.001);
    }

    public function test_a_category_that_changed_since_the_screen_opened_is_rejected_without_writing_anything(): void
    {
        [$batch, $caravans] = $this->mixedBatch();

        $this->postJson('http://test.localhost/api/weighing-orders/register-batch', $this->averagesPayload($batch, [
            ['category_id' => $caravans['VACA'][0]->category_id, 'head_count' => 3, 'average_weight' => 450],
        ]), $this->headers())
            ->assertStatus(422)
            ->assertJsonPath('code', 'GROUP_COVERAGE_MISMATCH')
            ->assertJsonPath('message', 'El pesaje declara 3 cabezas de Vaca Adulta y el lote tiene 2. Sobran 1: revisá el conteo.');

        $this->assertSame(0, WeighingOrder::count());
    }

    public function test_a_category_cannot_be_declared_twice(): void
    {
        [$batch, $caravans] = $this->mixedBatch();
        $vaca = $caravans['VACA'][0]->category_id;

        $this->postJson('http://test.localhost/api/weighing-orders/register-batch', $this->averagesPayload($batch, [
            ['category_id' => $vaca, 'head_count' => 2, 'average_weight' => 450],
            ['category_id' => $vaca, 'head_count' => 2, 'average_weight' => 430],
        ]), $this->headers())
            ->assertStatus(422)
            ->assertJsonPath('code', 'DUPLICATE_CATEGORY_AVERAGE');
    }

    public function test_a_general_average_needs_at_least_one_category(): void
    {
        [$batch] = $this->batchWith(1);

        $this->postJson('http://test.localhost/api/weighing-orders/register-batch', $this->averagesPayload($batch, []), $this->headers())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['averages']);
    }

    public function test_one_average_for_the_whole_batch_is_no_longer_accepted(): void
    {
        [$batch] = $this->batchWith(1);

        $this->postJson('http://test.localhost/api/weighing-orders/register-batch', [
            'batch_id' => $batch->id,
            'weighing_type' => 'BATCH_CONTROL',
            'weighing_date' => '2026-10-01',
            'entry' => 'AVERAGE',
            'average_weight' => 412.5,
            'head_count' => 1,
        ], $this->headers())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['entry']);
    }

    public function test_each_purpose_only_takes_its_own_entry(): void
    {
        [$batch] = $this->batchWith(1);

        $this->postJson('http://test.localhost/api/weighing-orders/register-batch', $this->payload($batch, [
            ['head_count' => 1, 'gross_weight' => 300],
        ], ['weighing_type' => 'BATCH_AVERAGE']), $this->headers())
            ->assertStatus(422)
            ->assertJsonPath('code', 'TYPE_ENTRY_MISMATCH');
    }

    public function test_an_order_registered_with_one_average_can_still_be_read(): void
    {
        [$batch] = $this->batchWith(1);

        WeighingOrder::create([
            'company_id' => $this->company->id,
            'code' => 'PS-20260901-0001',
            'status' => 'EXECUTED',
            'kind' => 'REGISTERED',
            'mode' => 'GROUP',
            'weighing_type_id' => WeighingType::idOf(WeighingTypeCode::BATCH_CONTROL),
            'batch_id' => $batch->id,
            'batch_entry' => 'AVERAGE',
            'declared_head_count' => 1,
            'declared_average_weight' => 412.5,
            'weighing_date' => '2026-09-01',
            'planned_head_count' => 1,
        ]);

        $this->getJson("http://test.localhost/api/batches/{$batch->id}/weighing-orders", $this->headers())
            ->assertOk()
            ->assertJsonPath('0.entry', 'AVERAGE')
            ->assertJsonPath('0.average_weight', 412.5);
    }

    public function test_each_cage_averages_only_its_own_category(): void
    {
        [$batch, $caravans] = $this->mixedBatch();
        $vaca = $caravans['VACA'][0]->category_id;
        $ternero = $caravans['TERNERO'][0]->category_id;

        $response = $this->postJson('http://test.localhost/api/weighing-orders/register-batch', $this->payload($batch, [
            ['category_id' => $vaca, 'head_count' => 1, 'gross_weight' => 440],
            ['category_id' => $vaca, 'head_count' => 1, 'gross_weight' => 460],
            ['category_id' => $ternero, 'head_count' => 1, 'gross_weight' => 120],
        ]), $this->headers())->assertStatus(201);

        $response->assertJsonPath('groups.0.category_name', 'Vaca Adulta')
            ->assertJsonPath('groups.2.category_id', $ternero)
            ->assertJsonPath('category_averages.0.category_id', $vaca)
            ->assertJsonPath('category_averages.0.head_count', 2);

        $this->assertEqualsWithDelta(450.0, (float) $this->currentWeight($caravans['VACA'][0])->weight, 0.001);
        $this->assertEqualsWithDelta(450.0, (float) $this->currentWeight($caravans['VACA'][1])->weight, 0.001);
        $this->assertEqualsWithDelta(120.0, (float) $this->currentWeight($caravans['TERNERO'][0])->weight, 0.001);
        $this->assertEqualsWithDelta(1020.0, (float) $batch->fresh()->total_weight, 0.01);

        // The order still reads back with its categories.
        $this->getJson("http://test.localhost/api/batches/{$batch->id}/weighing-orders", $this->headers())
            ->assertOk()
            ->assertJsonPath('0.groups.1.category_id', $vaca);
    }

    public function test_a_category_without_cages_keeps_its_weight(): void
    {
        [$batch, $caravans] = $this->mixedBatch();
        $calf = $caravans['TERNERO'][0];
        $before = (float) $this->currentWeight($calf)->weight;

        $this->postJson('http://test.localhost/api/weighing-orders/register-batch', $this->payload($batch, [
            ['category_id' => $caravans['VACA'][0]->category_id, 'head_count' => 2, 'gross_weight' => 900],
        ]), $this->headers())->assertStatus(201)->assertJsonPath('planned_head_count', 2);

        $this->assertEqualsWithDelta($before, (float) $this->currentWeight($calf)->weight, 0.001);
    }

    public function test_a_mixed_batch_cannot_be_weighed_by_cage_without_declaring_the_category(): void
    {
        [$batch] = $this->mixedBatch();

        $this->postJson('http://test.localhost/api/weighing-orders/register-batch', $this->payload($batch, [
            ['head_count' => 3, 'gross_weight' => 1020],
        ]), $this->headers())
            ->assertStatus(422)
            ->assertJsonPath('code', 'GROUP_COVERAGE_MISMATCH');

        $this->assertSame(0, WeighingOrder::count());
    }

    public function test_loads_that_do_not_cover_the_batch_are_rejected_without_writing_anything(): void
    {
        [$batch, $caravans] = $this->batchWith(3);

        $this->postJson('http://test.localhost/api/weighing-orders/register-batch', $this->payload($batch, [
            ['head_count' => 2, 'gross_weight' => 900],
        ]), $this->headers())
            ->assertStatus(422)
            ->assertJsonPath('code', 'GROUP_COVERAGE_MISMATCH');

        $this->assertSame(0, WeighingOrder::count());
        $this->assertSame(3, CaravanWeight::whereIn('caravan_id', array_map(fn ($c) => $c->id, $caravans))->count());
    }

    public function test_a_load_with_gross_below_tare_is_rejected(): void
    {
        [$batch] = $this->batchWith(1);

        $this->postJson('http://test.localhost/api/weighing-orders/register-batch', $this->payload($batch, [
            ['head_count' => 1, 'gross_weight' => 300, 'tare_weight' => 350],
        ]), $this->headers())
            ->assertStatus(422)
            ->assertJsonPath('code', 'GROUP_GROSS_NOT_ABOVE_TARE');
    }

    public function test_purposes_measured_animal_by_animal_are_not_offered_for_a_batch(): void
    {
        [$batch] = $this->batchWith(1);

        $this->postJson('http://test.localhost/api/weighing-orders/register-batch', $this->payload($batch, [
            ['head_count' => 1, 'gross_weight' => 300],
        ], ['weighing_type' => 'CONTROL']), $this->headers())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['weighing_type']);

        foreach (['WEANING', 'PRE_SERVICE', 'PREGNANCY_CHECK', 'ENTRY', 'EXIT', 'EMERGENCY'] as $type) {
            $this->postJson('http://test.localhost/api/weighing-orders/register-batch', $this->payload($batch, [
                ['head_count' => 1, 'gross_weight' => 300],
            ], ['weighing_type' => $type]), $this->headers())
                ->assertStatus(422)
                ->assertJsonValidationErrors(['weighing_type']);
        }
    }

    public function test_a_single_caravan_cannot_be_weighed_as_a_batch(): void
    {
        [, $caravans] = $this->batchWith(1);
        $id = $caravans[0]->id;

        $this->postJson("http://test.localhost/api/caravans/{$id}/weights", [
            'weight' => 410, 'weighing_date' => '2026-10-01', 'weighing_type' => 'BATCH_CONTROL',
        ], $this->headers())->assertStatus(422)->assertJsonValidationErrors(['weighing_type']);

        $this->postJson("http://test.localhost/api/caravans/{$caravans[0]->id}/weights", [
            'weight' => 410, 'weighing_date' => '2026-10-01', 'weighing_type' => 'BATCH_AVERAGE',
        ], $this->headers())->assertStatus(422)->assertJsonValidationErrors(['weighing_type']);

        $this->postJson("http://test.localhost/api/caravans/{$id}/weights", [
            'weight' => 410, 'weighing_date' => '2026-10-01', 'method' => 'AVERAGE',
        ], $this->headers())->assertStatus(422)->assertJsonValidationErrors(['method']);
    }

    public function test_a_late_cage_weighing_does_not_displace_a_newer_individual_weight(): void
    {
        [$batch, $caravans] = $this->batchWith(2);

        CaravanWeight::where('caravan_id', $caravans[0]->id)->update(['current' => false]);
        CaravanWeight::create([
            'weighing_type_id' => WeighingType::idOf(WeighingTypeCode::CONTROL),
            'caravan_id' => $caravans[0]->id,
            'weight' => 455,
            'weighing_date' => '2026-10-05',
            'current' => true,
        ]);

        $this->postJson('http://test.localhost/api/weighing-orders/register-batch', $this->payload($batch, [
            ['head_count' => 2, 'gross_weight' => 860],
        ]), $this->headers())->assertStatus(201);

        $current = CaravanWeight::where('caravan_id', $caravans[0]->id)->where('current', true)->firstOrFail();
        $this->assertEqualsWithDelta(455.0, (float) $current->weight, 0.01);

        $other = CaravanWeight::where('caravan_id', $caravans[1]->id)->where('current', true)->firstOrFail();
        $this->assertEqualsWithDelta(430.0, (float) $other->weight, 0.01);
    }
}
