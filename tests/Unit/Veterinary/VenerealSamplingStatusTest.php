<?php

declare(strict_types=1);

namespace Tests\Unit\Veterinary;

use App\Core\Enums\LabSampleStatus;
use App\Core\ValueObjects\SampleResult;
use App\Core\ValueObjects\VenerealSamplingStatus;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * ADR-4 counting rules, isolated from persistence.
 */
class VenerealSamplingStatusTest extends TestCase
{
    private const TRICHOMONAS = 'TRITRICHOMONAS_FOETUS';
    private const CAMPYLOBACTER = 'CAMPYLOBACTER_FETUS';

    public function test_an_empty_status_certifies_nothing(): void
    {
        $sampling = VenerealSamplingStatus::empty();

        $this->assertFalse($sampling->hasAnySample());
        $this->assertFalse($sampling->hasPendingResults());
        $this->assertFalse($sampling->hasStandingPositive());
        $this->assertSame(0, $sampling->negativeRoundsFor(self::TRICHOMONAS, 365));
    }

    public function test_repeating_the_same_round_does_not_count_twice(): void
    {
        $sampling = new VenerealSamplingStatus([
            $this->negative(self::TRICHOMONAS, 1, '-20 days'),
            $this->negative(self::TRICHOMONAS, 1, '-19 days'),
        ]);

        $this->assertSame(1, $sampling->negativeRoundsFor(self::TRICHOMONAS, 365));
    }

    public function test_clearance_requires_every_configured_pathogen(): void
    {
        $sampling = new VenerealSamplingStatus([
            $this->negative(self::TRICHOMONAS, 1, '-20 days'),
            $this->negative(self::TRICHOMONAS, 2, '-10 days'),
        ]);

        $this->assertTrue($sampling->isClearedFor([self::TRICHOMONAS], 2, 365));
        $this->assertFalse($sampling->isClearedFor([self::TRICHOMONAS, self::CAMPYLOBACTER], 2, 365));
    }

    public function test_a_positive_stands_until_a_later_negative_supersedes_it(): void
    {
        $withStandingPositive = new VenerealSamplingStatus([
            $this->negative(self::TRICHOMONAS, 1, '-60 days'),
            new SampleResult(self::TRICHOMONAS, 2, new DateTimeImmutable('-30 days'), LabSampleStatus::POSITIVE_DETECTED),
        ]);

        $this->assertTrue($withStandingPositive->hasStandingPositive());

        $superseded = new VenerealSamplingStatus([
            new SampleResult(self::TRICHOMONAS, 1, new DateTimeImmutable('-60 days'), LabSampleStatus::POSITIVE_DETECTED),
            $this->negative(self::TRICHOMONAS, 2, '-30 days'),
        ]);

        $this->assertFalse($superseded->hasStandingPositive());
    }

    public function test_negatives_taken_before_the_last_positive_are_not_counted(): void
    {
        $sampling = new VenerealSamplingStatus([
            $this->negative(self::TRICHOMONAS, 1, '-90 days'),
            $this->negative(self::TRICHOMONAS, 2, '-80 days'),
            new SampleResult(self::TRICHOMONAS, 3, new DateTimeImmutable('-40 days'), LabSampleStatus::POSITIVE_DETECTED),
            $this->negative(self::TRICHOMONAS, 4, '-10 days'),
        ]);

        // Only round 4 postdates the positive, so the clearance is incomplete again.
        $this->assertSame(1, $sampling->negativeRoundsFor(self::TRICHOMONAS, 365));
        $this->assertFalse($sampling->hasStandingPositive());
    }

    public function test_the_validity_window_expires_old_clearances(): void
    {
        $sampling = new VenerealSamplingStatus([
            $this->negative(self::TRICHOMONAS, 1, '-400 days'),
            $this->negative(self::TRICHOMONAS, 2, '-390 days'),
        ]);

        $this->assertSame(0, $sampling->negativeRoundsFor(self::TRICHOMONAS, 365));
        $this->assertSame(2, $sampling->negativeRoundsFor(self::TRICHOMONAS, 500));
    }

    public function test_pending_results_are_reported_for_the_engine_to_hold_the_bull(): void
    {
        $sampling = new VenerealSamplingStatus([
            new SampleResult(self::TRICHOMONAS, 1, new DateTimeImmutable('-2 days'), LabSampleStatus::PENDING_RESULTS),
        ]);

        $this->assertTrue($sampling->hasPendingResults());
        $this->assertSame(0, $sampling->negativeRoundsFor(self::TRICHOMONAS, 365));
    }

    public function test_the_breakdown_reports_progress_per_required_pathogen(): void
    {
        $sampling = new VenerealSamplingStatus([
            $this->negative(self::TRICHOMONAS, 1, '-20 days'),
            $this->negative(self::TRICHOMONAS, 2, '-10 days'),
            $this->negative(self::CAMPYLOBACTER, 1, '-20 days'),
        ]);

        $this->assertSame(
            [self::TRICHOMONAS => 2, self::CAMPYLOBACTER => 1],
            $sampling->negativeRoundsBreakdown([self::TRICHOMONAS, self::CAMPYLOBACTER], 365)
        );
    }

    private function negative(string $pathogenCode, int $round, string $relativeDate): SampleResult
    {
        return new SampleResult(
            $pathogenCode,
            $round,
            new DateTimeImmutable($relativeDate),
            LabSampleStatus::NEGATIVE_CLEARED
        );
    }
}
