<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Services\TroopWeightOutlierDetector;
use PHPUnit\Framework\TestCase;

final class TroopWeightOutlierDetectorTest extends TestCase
{
    public function test_a_weight_with_an_extra_digit_stands_out_against_the_median(): void
    {
        $outliers = (new TroopWeightOutlierDetector())->outliers([1 => 172.0, 2 => 1850.0, 3 => 185.5, 4 => 176.0]);

        $this->assertSame([2], array_keys($outliers));
        $this->assertTrue($outliers[2]['above']);
        // The median, not the average: the typo cannot hide itself by dragging the reference.
        $this->assertEqualsWithDelta(180.75, $outliers[2]['median'], 0.001);
    }

    public function test_a_weight_below_half_the_median_stands_out_too(): void
    {
        $outliers = (new TroopWeightOutlierDetector())->outliers(['a' => 18.5, 'b' => 180.0, 'c' => 175.0, 'd' => 190.0]);

        $this->assertSame(['a'], array_keys($outliers));
        $this->assertFalse($outliers['a']['above']);
    }

    public function test_an_ordinary_spread_is_left_alone(): void
    {
        $this->assertSame([], (new TroopWeightOutlierDetector())->outliers([158.0, 199.0, 174.0, 188.0, 162.5]));
    }

    public function test_too_few_weights_say_nothing(): void
    {
        $this->assertSame([], (new TroopWeightOutlierDetector())->outliers([170.0, 1850.0]));
    }

    public function test_zero_and_negative_weights_are_not_its_business(): void
    {
        // They are rejected by the use case; here they must not bend the median either.
        $this->assertSame([], (new TroopWeightOutlierDetector())->outliers([0.0, -5.0, 170.0, 175.0, 180.0]));
    }
}
