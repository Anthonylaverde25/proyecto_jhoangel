<?php

declare(strict_types=1);

namespace Tests\Feature\Weighing;

use App\Core\Enums\WeighingTypeCode;
use App\Models\Activity;
use App\Models\Batch;
use App\Models\BatchType;
use App\Models\BatchWeight;
use App\Models\Caravan;
use App\Models\CaravanWeight;
use App\Models\Company;
use App\Models\Tenant;
use App\Models\WeighingOrder;
use App\Models\WeighingType;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * A cage weighing measures the batch: the loads stay on a registered order and each caravan
 * receives the average, so the mass of the batch is exactly the net mass of the scale.
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

    public function test_an_average_already_worked_out_is_registered_as_declared_not_as_a_load(): void
    {
        [$batch, $caravans] = $this->batchWith(3);

        $response = $this->postJson('http://test.localhost/api/weighing-orders/register-batch', [
            'batch_id' => $batch->id,
            'weighing_type' => 'BATCH_CONTROL',
            'weighing_date' => '2026-10-01',
            'entry' => 'AVERAGE',
            'average_weight' => 412.5,
            'head_count' => 3,
        ], $this->headers())->assertStatus(201);

        $response->assertJsonPath('entry', 'AVERAGE')
            ->assertJsonPath('groups', [])
            ->assertJsonPath('total_heads', 3);
        $this->assertEqualsWithDelta(1237.5, $response->json('total_net_weight'), 0.01);

        $order = WeighingOrder::firstOrFail();
        $this->assertSame('AVERAGE', $order->batch_entry);
        $this->assertSame(3, $order->declared_head_count);
        $this->assertEqualsWithDelta(412.5, (float) $order->declared_average_weight, 0.001);
        $this->assertSame(0, \App\Models\WeighingOrderGroup::count());

        foreach ($caravans as $caravan) {
            $current = CaravanWeight::where('caravan_id', $caravan->id)->where('current', true)->firstOrFail();
            $this->assertEqualsWithDelta(412.5, (float) $current->weight, 0.001);
            $this->assertSame('AVERAGE', $current->method);
        }

        $point = BatchWeight::where('batch_id', $batch->id)->latest('id')->firstOrFail();
        $this->assertEqualsWithDelta(1237.5, (float) $point->total_weight, 0.01);
    }

    public function test_a_declared_average_over_fewer_heads_than_the_batch_is_rejected(): void
    {
        [$batch] = $this->batchWith(3);

        $this->postJson('http://test.localhost/api/weighing-orders/register-batch', [
            'batch_id' => $batch->id,
            'weighing_type' => 'BATCH_CONTROL',
            'weighing_date' => '2026-10-01',
            'entry' => 'AVERAGE',
            'average_weight' => 412.5,
            'head_count' => 2,
        ], $this->headers())
            ->assertStatus(422)
            ->assertJsonPath('code', 'GROUP_COVERAGE_MISMATCH')
            ->assertJsonPath('message', 'El pesaje declara 2 cabezas y el lote tiene 3. Faltan 1: pesalas o revisá el conteo.');

        $this->assertSame(0, WeighingOrder::count());
    }

    public function test_an_average_entry_needs_its_average_and_heads(): void
    {
        [$batch] = $this->batchWith(1);

        $this->postJson('http://test.localhost/api/weighing-orders/register-batch', [
            'batch_id' => $batch->id,
            'weighing_type' => 'BATCH_CONTROL',
            'weighing_date' => '2026-10-01',
            'entry' => 'AVERAGE',
        ], $this->headers())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['average_weight', 'head_count']);
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
    }

    public function test_a_single_caravan_cannot_be_weighed_as_a_batch(): void
    {
        [, $caravans] = $this->batchWith(1);
        $id = $caravans[0]->id;

        $this->postJson("http://test.localhost/api/caravans/{$id}/weights", [
            'weight' => 410, 'weighing_date' => '2026-10-01', 'weighing_type' => 'BATCH_CONTROL',
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
