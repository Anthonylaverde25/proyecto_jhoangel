<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Enums\BatchWeighingEntry;
use App\Core\Exceptions\WeighingOrderDomainException;
use App\Core\Services\GroupWeightImputation;
use App\Core\ValueObjects\BatchWeighingMeasure;
use App\Core\ValueObjects\CageGroupWeighing;
use App\Core\ValueObjects\CategoryAverageWeighing;
use PHPUnit\Framework\TestCase;

class GroupWeightImputationTest extends TestCase
{
    /** @return array<int, ?int> caravan id => category id */
    private function animals(int $from, int $to, ?int $categoryId = null): array
    {
        return array_fill_keys(range($from, $to), $categoryId);
    }

    public function test_every_animal_receives_the_average_of_the_loads_of_its_category(): void
    {
        $measure = BatchWeighingMeasure::fromLoads([
            new CageGroupWeighing(1, 20, 8360.0),
            new CageGroupWeighing(2, 20, 8440.0),
            new CageGroupWeighing(3, 20, 8420.0, 20.0),
        ]);

        $weights = (new GroupWeightImputation())->impute($measure, $this->animals(1, 60));

        $this->assertSame(BatchWeighingEntry::LOADS, $measure->entry);
        $this->assertCount(60, $weights);
        $this->assertSame(420.0, $weights[1]);
        $this->assertSame(420.0, $weights[60]);
        $this->assertEqualsWithDelta(25200.0, array_sum($weights), 0.01);
    }

    public function test_loads_of_different_categories_never_share_an_average(): void
    {
        $measure = BatchWeighingMeasure::fromLoads([
            new CageGroupWeighing(1, 3, 1350.0, categoryId: 3),
            new CageGroupWeighing(2, 2, 900.0, categoryId: 3),
            new CageGroupWeighing(3, 5, 500.0, categoryId: 1),
        ]);

        $weights = (new GroupWeightImputation())->impute($measure, $this->animals(1, 5, 3) + $this->animals(6, 10, 1));

        $this->assertSame(450.0, $weights[1]);
        $this->assertSame(450.0, $weights[5]);
        $this->assertSame(100.0, $weights[6]);
        $this->assertEqualsWithDelta(2750.0, array_sum($weights), 0.01);
    }

    public function test_a_category_without_loads_is_left_out(): void
    {
        $measure = BatchWeighingMeasure::fromLoads([new CageGroupWeighing(1, 5, 2250.0, categoryId: 3)]);

        $weights = (new GroupWeightImputation())->impute($measure, $this->animals(1, 5, 3) + $this->animals(6, 10, 1));

        $this->assertSame([1, 2, 3, 4, 5], array_keys($weights));
    }

    public function test_a_category_average_is_assigned_as_declared(): void
    {
        $measure = BatchWeighingMeasure::fromCategoryAverages([new CategoryAverageWeighing(3, 60, 420.0)]);

        $weights = (new GroupWeightImputation())->impute($measure, $this->animals(1, 60, 3));

        $this->assertSame(BatchWeighingEntry::BY_CATEGORY, $measure->entry);
        $this->assertSame([], $measure->loads);
        $this->assertSame(25200.0, $measure->netWeight);
        $this->assertSame(420.0, $weights[30]);
    }

    public function test_loads_that_miss_animals_of_their_category_are_rejected(): void
    {
        $this->expectException(WeighingOrderDomainException::class);
        $this->expectExceptionMessage('Las jaulas suman 3 cabezas de Vaca Adulta y el lote tiene 5. Faltan 2');

        (new GroupWeightImputation())->impute(
            BatchWeighingMeasure::fromLoads([new CageGroupWeighing(1, 3, 1350.0, categoryId: 3)]),
            $this->animals(1, 5, 3),
            [3 => 'Vaca Adulta']
        );
    }

    public function test_an_average_out_of_range_is_invalid(): void
    {
        $this->expectException(WeighingOrderDomainException::class);
        BatchWeighingMeasure::fromAverage(10, 0.0);
    }

    public function test_a_load_without_heads_is_invalid(): void
    {
        $this->expectException(WeighingOrderDomainException::class);
        new CageGroupWeighing(1, 0, 400.0);
    }

    public function test_a_load_needs_gross_above_tare(): void
    {
        $this->expectException(WeighingOrderDomainException::class);
        new CageGroupWeighing(1, 2, 300.0, 300.0);
    }
}
