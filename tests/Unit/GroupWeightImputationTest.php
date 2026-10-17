<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Enums\BatchWeighingEntry;
use App\Core\Exceptions\WeighingOrderDomainException;
use App\Core\Services\GroupWeightImputation;
use App\Core\ValueObjects\BatchWeighingMeasure;
use App\Core\ValueObjects\CageGroupWeighing;
use PHPUnit\Framework\TestCase;

class GroupWeightImputationTest extends TestCase
{
    public function test_every_animal_receives_the_overall_average_of_the_loads(): void
    {
        $measure = BatchWeighingMeasure::fromLoads([
            new CageGroupWeighing(1, 20, 8360.0),
            new CageGroupWeighing(2, 20, 8440.0),
            new CageGroupWeighing(3, 20, 8420.0, 20.0),
        ]);

        $weights = (new GroupWeightImputation())->impute($measure, range(1, 60));

        $this->assertSame(BatchWeighingEntry::LOADS, $measure->entry);
        $this->assertCount(60, $weights);
        $this->assertSame(420.0, $weights[1]);
        $this->assertSame(420.0, $weights[60]);
        $this->assertEqualsWithDelta(25200.0, array_sum($weights), 0.01);
    }

    public function test_a_declared_average_is_assigned_as_is(): void
    {
        $measure = BatchWeighingMeasure::fromAverage(60, 420.0);

        $weights = (new GroupWeightImputation())->impute($measure, range(1, 60));

        $this->assertSame(BatchWeighingEntry::AVERAGE, $measure->entry);
        $this->assertSame([], $measure->loads);
        $this->assertSame(25200.0, $measure->netWeight);
        $this->assertSame(420.0, $weights[30]);
    }

    public function test_loads_that_miss_animals_are_rejected(): void
    {
        $this->expectException(WeighingOrderDomainException::class);
        $this->expectExceptionMessage('Las jaulas suman 55 cabezas y el lote tiene 60. Faltan 5');

        (new GroupWeightImputation())->impute(BatchWeighingMeasure::fromLoads([new CageGroupWeighing(1, 55, 23000.0)]), range(1, 60));
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
