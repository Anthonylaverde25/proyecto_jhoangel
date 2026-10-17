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
use App\Models\WeighingType;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * The single writer of caravan weights: every weight carries its purpose, and a weighing loaded
 * late never displaces a newer current weight.
 */
class CaravanWeighingTest extends TestCase
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

    private function batchWithAnimal(string $identification, float $weight, string $date): array
    {
        $batch = Batch::create([
            'company_id' => $this->company->id,
            'name' => 'Lote Pesaje ' . $identification,
            'activity_id' => Activity::where('code', 'RECRIA')->firstOrFail()->id,
            'batch_type_id' => BatchType::where('code', 'GROWING_STEERS')->firstOrFail()->id,
            'is_active' => true,
        ]);

        $caravan = Caravan::create([
            'company_id' => $this->company->id,
            'batch_id' => $batch->id,
            'identification' => $identification,
            'sex' => 'M',
            'teeth' => 0,
        ]);

        CaravanWeight::create([
            'weighing_type_id' => WeighingType::idOf(WeighingTypeCode::CONTROL),
            'caravan_id' => $caravan->id,
            'weight' => $weight,
            'weighing_date' => $date,
            'current' => true,
        ]);

        return [$batch, $caravan];
    }

    public function test_every_seeded_weight_has_a_type(): void
    {
        $this->assertGreaterThan(0, CaravanWeight::count());
        $this->assertSame(0, CaravanWeight::whereNull('weighing_type_id')->count());
        $this->assertSame(count(WeighingTypeCode::cases()), WeighingType::count());
    }

    public function test_a_weighing_loaded_late_joins_the_history_without_displacing_the_current_one(): void
    {
        [$batch, $caravan] = $this->batchWithAnimal('PES-LATE-01', 320.0, '2026-09-01');

        $this->postJson("http://test.localhost/api/caravans/{$caravan->id}/weights", [
            'weight' => 290.0,
            'weighing_date' => '2026-08-01',
        ], $this->headers())->assertStatus(201);

        $current = CaravanWeight::where('caravan_id', $caravan->id)->where('current', true)->get();
        $this->assertCount(1, $current);
        $this->assertEqualsWithDelta(320.0, (float) $current->first()->weight, 0.01);

        $late = CaravanWeight::where('caravan_id', $caravan->id)->where('weight', 290.0)->firstOrFail();
        $this->assertFalse($late->current);

        $point = BatchWeight::where('batch_id', $batch->id)->latest('id')->firstOrFail();
        $this->assertSame('2026-08-01', $point->weighing_date->format('Y-m-d'));
        $this->assertEqualsWithDelta(320.0, (float) $point->weight, 0.01);
    }

    public function test_a_weighing_records_its_purpose_method_and_shrink_basis(): void
    {
        [$batch, $caravan] = $this->batchWithAnimal('PES-TYPE-01', 400.0, '2026-08-01');

        $this->postJson("http://test.localhost/api/caravans/{$caravan->id}/weights", [
            'weight' => 432.5,
            'weighing_date' => '2026-09-10',
            'weighing_time' => '07:30',
            'weighing_type' => 'PREGNANCY_CHECK',
            'shrunk_condition' => 'CALCULATED',
            'shrink_pct' => 6,
        ], $this->headers())->assertStatus(201);

        $weight = CaravanWeight::where('caravan_id', $caravan->id)->where('current', true)->firstOrFail();
        $this->assertSame(WeighingType::idOf(WeighingTypeCode::PREGNANCY_CHECK), $weight->weighing_type_id);
        $this->assertSame('INDIVIDUAL', $weight->method);
        $this->assertSame('CALCULATED', $weight->shrunk_condition);
        $this->assertEqualsWithDelta(6.0, (float) $weight->shrink_pct, 0.001);
        $this->assertSame('MANUAL', $weight->source);
        $this->assertStringStartsWith('07:30', (string) $weight->weighing_time);

        $point = BatchWeight::where('batch_id', $batch->id)->latest('id')->firstOrFail();
        $this->assertSame('CONTROL', $point->type);
        $this->assertSame(WeighingType::idOf(WeighingTypeCode::PREGNANCY_CHECK), $point->weighing_type_id);

        $history = $this->getJson("http://test.localhost/api/caravans/{$caravan->id}/weights", $this->headers())
            ->assertOk()
            ->json();
        $this->assertSame('PREGNANCY_CHECK', $history[0]['weighing_type']);
        $this->assertFalse($history[0]['affects_adpv']);
        $this->assertSame('CONTROL', $history[1]['weighing_type']);
    }

    public function test_a_weight_of_zero_and_a_calculated_shrink_without_percentage_are_rejected(): void
    {
        [, $caravan] = $this->batchWithAnimal('PES-BAD-01', 400.0, '2026-08-01');

        $this->postJson("http://test.localhost/api/caravans/{$caravan->id}/weights", [
            'weight' => 0,
            'weighing_date' => '2026-09-10',
        ], $this->headers())->assertStatus(422)->assertJsonValidationErrors(['weight']);

        $this->postJson("http://test.localhost/api/caravans/{$caravan->id}/weights", [
            'weight' => 410,
            'weighing_date' => '2026-09-10',
            'shrunk_condition' => 'CALCULATED',
        ], $this->headers())->assertStatus(422)->assertJsonValidationErrors(['shrink_pct']);

        $this->assertSame(1, CaravanWeight::where('caravan_id', $caravan->id)->count());
    }

    public function test_a_bulk_weighing_dates_the_batch_point_on_the_weighing_day(): void
    {
        [$batch, $caravan] = $this->batchWithAnimal('PES-BULK-01', 250.0, '2026-07-01');

        $this->postJson('http://test.localhost/api/caravans/bulk-weights', [
            'weights' => [[
                'caravan_id' => $caravan->id,
                'weight' => 268.0,
                'weighing_date' => '2026-08-15',
            ]],
        ], $this->headers())->assertStatus(201);

        $point = BatchWeight::where('batch_id', $batch->id)->latest('id')->firstOrFail();
        $this->assertSame('2026-08-15', $point->weighing_date->format('Y-m-d'));
        $this->assertSame(WeighingType::idOf(WeighingTypeCode::CONTROL), $point->weighing_type_id);
    }

    public function test_the_catalog_lists_in_cycle_order_and_a_type_can_be_hidden(): void
    {
        $types = $this->getJson('http://test.localhost/api/weighing-types', $this->headers())->assertOk()->json();

        $this->assertSame(array_map(fn (WeighingTypeCode $c) => $c->value, WeighingTypeCode::cases()), array_column($types, 'code'));
        $entry = collect($types)->firstWhere('code', 'ENTRY');
        $this->assertFalse($entry['affects_adpv']);
        $this->assertSame('AVERAGE', $entry['default_method']);

        $birthId = WeighingType::idOf(WeighingTypeCode::BIRTH);
        $this->patchJson("http://test.localhost/api/weighing-types/{$birthId}/active", ['is_active' => false], $this->headers())
            ->assertOk()
            ->assertJsonPath('is_active', false);

        $active = $this->getJson('http://test.localhost/api/weighing-types?only_active=1', $this->headers())->assertOk()->json();
        $this->assertNotContains('BIRTH', array_column($active, 'code'));
    }
}
