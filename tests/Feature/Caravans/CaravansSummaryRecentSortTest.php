<?php

declare(strict_types=1);

namespace Tests\Feature\Caravans;

use App\Models\AnimalCategory;
use App\Models\Batch;
use App\Models\Caravan;
use App\Models\CaravanGestation;
use Tests\Feature\Veterinary\VeterinaryTestCase;

/**
 * GET /caravans/summary: the light, paginated caravan list behind the inventory tables.
 */
class CaravansSummaryRecentSortTest extends VeterinaryTestCase
{
    private Batch $batch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->batch = Batch::create(['company_id' => $this->company->id, 'name' => 'Lote Resumen', 'is_active' => true]);
    }

    public function test_recent_lists_the_latest_arrivals_to_the_batch_first(): void
    {
        $this->caravan('SUM-A', 'H', '2026-09-01 08:00:00');
        $this->caravan('SUM-B', 'M', '2026-10-01 08:00:00');
        $this->caravan('SUM-C', 'H', '2026-08-01 08:00:00');

        $recent = $this->apiAs('GET', "/caravans/summary?batch_id={$this->batch->id}&sort=recent&per_page=2")->assertOk()->json();

        $this->assertSame(['SUM-B', 'SUM-A'], array_column($recent['data'], 'identification'));
        $this->assertSame('2026-10-01', $recent['data'][0]['batch_assigned_at']);
        $this->assertSame(['current_page' => 1, 'last_page' => 2, 'per_page' => 2, 'total' => 3, 'from' => 1, 'to' => 2], $recent['meta']);

        // Without sort, the order stays the identification one.
        $default = $this->apiAs('GET', "/caravans/summary?batch_id={$this->batch->id}")->json('data');
        $this->assertSame(['SUM-A', 'SUM-B', 'SUM-C'], array_column($default, 'identification'));
    }

    public function test_each_female_says_whether_she_carries_a_gestation(): void
    {
        $pregnant = $this->caravan('SUM-H1', 'H', '2026-09-01 08:00:00');
        $this->caravan('SUM-H2', 'H', '2026-09-01 08:00:00');
        $this->caravan('SUM-M1', 'M', '2026-09-01 08:00:00');
        CaravanGestation::create(['caravan_id' => $pregnant->id, 'is_current' => true]);

        $rows = collect($this->apiAs('GET', "/caravans/summary?batch_id={$this->batch->id}")->json('data'))
            ->pluck('gestation_status', 'identification')
            ->all();

        $this->assertSame(['SUM-H1' => 'PREGNANT', 'SUM-H2' => 'NONE', 'SUM-M1' => null], $rows);
    }

    public function test_breeding_does_not_apply_to_a_calf(): void
    {
        $calfCategory = AnimalCategory::updateOrCreate(['code' => 'TERNERO'], ['name' => 'Ternero', 'sex' => 'BOTH', 'is_reproductive' => false]);
        $heiferCategory = AnimalCategory::updateOrCreate(['code' => 'VAQUILLONA'], ['name' => 'Vaquillona', 'sex' => 'H', 'is_reproductive' => true]);
        $this->caravan('SUM-CALF', 'H', '2026-09-01 08:00:00')->update(['category_id' => $calfCategory->id]);
        $this->caravan('SUM-HEIFER', 'H', '2026-09-01 08:00:00')->update(['category_id' => $heiferCategory->id]);
        $this->caravan('SUM-OUT', 'H', '2026-09-01 08:00:00')->update(['category_id' => $heiferCategory->id, 'is_breeding_eligible' => false]);
        $this->caravan('SUM-NOCAT', 'H', '2026-09-01 08:00:00');

        $rows = collect($this->apiAs('GET', "/caravans/summary?batch_id={$this->batch->id}")->json('data'))->keyBy('identification');

        $this->assertSame('NOT_APPLICABLE', $rows['SUM-CALF']['reproductive_status']);
        $this->assertNull($rows['SUM-CALF']['gestation_status']);
        $this->assertFalse($rows['SUM-CALF']['is_reproductive_category']);
        $this->assertSame('ELIGIBLE', $rows['SUM-HEIFER']['reproductive_status']);
        $this->assertSame('NONE', $rows['SUM-HEIFER']['gestation_status']);
        $this->assertSame('EXCLUDED', $rows['SUM-OUT']['reproductive_status']);
        $this->assertSame('ELIGIBLE', $rows['SUM-NOCAT']['reproductive_status']);
        $this->assertNull($rows['SUM-NOCAT']['is_reproductive_category']);
    }

    public function test_unassigned_and_the_page_size_cap(): void
    {
        $this->caravan('SUM-X', 'H', '2026-09-01 08:00:00');
        Caravan::create(['company_id' => $this->company->id, 'identification' => 'SUM-FREE', 'sex' => 'M']);

        $unassigned = $this->apiAs('GET', '/caravans/summary?batch_id=unassigned&search=SUM')->json();
        $this->assertSame(['SUM-FREE'], array_column($unassigned['data'], 'identification'));

        $this->assertSame(1000, $this->apiAs('GET', '/caravans/summary?per_page=5000')->json('meta.per_page'));
    }

    private function caravan(string $identification, string $sex, string $assignedAt): Caravan
    {
        return Caravan::create([
            'company_id' => $this->company->id,
            'identification' => $identification,
            'batch_id' => $this->batch->id,
            'batch_assigned_at' => $assignedAt,
            'sex' => $sex,
        ]);
    }
}
