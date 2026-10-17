<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Enums\ShrunkCondition;
use App\Core\Enums\WeighingMethod;
use App\Core\Enums\WeighingSource;
use App\Core\Enums\WeighingTypeCode;
use App\Core\Exceptions\InvalidWeighingException;
use App\Core\ValueObjects\WeighingReading;
use PHPUnit\Framework\TestCase;

class WeighingReadingTest extends TestCase
{
    private function reading(float $weight, ShrunkCondition $condition = ShrunkCondition::FULL_WEIGHT, ?float $pct = null, ?string $time = null): WeighingReading
    {
        return new WeighingReading(
            weight: $weight,
            date: new \DateTimeImmutable('2026-10-01'),
            type: WeighingTypeCode::CONTROL,
            method: WeighingMethod::INDIVIDUAL,
            source: WeighingSource::MANUAL,
            shrunkCondition: $condition,
            shrinkPct: $pct,
            time: $time,
        );
    }

    public function test_a_weight_of_zero_or_less_is_never_valid(): void
    {
        $this->expectException(InvalidWeighingException::class);
        $this->reading(0.0);
    }

    public function test_a_calculated_shrink_needs_its_percentage(): void
    {
        $this->expectException(InvalidWeighingException::class);
        $this->reading(400.0, ShrunkCondition::CALCULATED);
    }

    public function test_a_percentage_without_calculated_shrink_is_rejected(): void
    {
        $this->expectException(InvalidWeighingException::class);
        $this->reading(400.0, ShrunkCondition::FULL_WEIGHT, 5.0);
    }

    public function test_an_invalid_time_is_rejected(): void
    {
        $this->expectException(InvalidWeighingException::class);
        $this->reading(400.0, time: '25:10');
    }

    public function test_a_valid_reading_keeps_the_measured_kilos(): void
    {
        $reading = $this->reading(412.4, ShrunkCondition::CALCULATED, 6.0, '07:30');

        $this->assertSame(412.4, $reading->weight);
        $this->assertSame(6.0, $reading->shrinkPct);
    }

    public function test_the_typical_reading_takes_the_shrink_basis_of_the_type(): void
    {
        $exit = WeighingReading::typical(460.0, new \DateTimeImmutable(), WeighingTypeCode::EXIT, WeighingSource::MANUAL);

        $this->assertSame(ShrunkCondition::SHRUNK_12H, $exit->shrunkCondition);
        $this->assertFalse(WeighingTypeCode::EXIT->affectsAdpv());
        $this->assertTrue(WeighingTypeCode::BATCH_CONTROL->affectsAdpv());
        $this->assertSame(WeighingMethod::PORTABLE_FIELD, WeighingTypeCode::BIRTH->defaultMethod());
    }
}
