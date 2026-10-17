<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Enums\LabSampleStatus;
use App\Core\Services\SanitaryExposureResolver;
use App\Core\ValueObjects\SampleResult;
use App\Core\ValueObjects\SanitaryExposure\BullParticipation;
use App\Core\ValueObjects\SanitaryExposure\ExposureOverlap;
use App\Core\ValueObjects\SanitaryExposure\FemaleExposure;
use App\Core\ValueObjects\VenerealSamplingStatus;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class SanitaryExposureResolverTest extends TestCase
{
    private const BULL = 10;

    private SanitaryExposureResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new SanitaryExposureResolver(365);
    }

    public function test_a_female_that_shared_the_paddock_is_exposed_until_either_left(): void
    {
        $overlaps = $this->resolver->overlaps(self::BULL, $this->d('2026-01-01'), [
            $this->stay(1, '2026-03-01', '2026-05-01'),
        ], [
            $this->female(1, 100, '2026-03-01', null),
            $this->female(1, 101, '2026-03-01', '2026-04-10'),
        ]);

        $this->assertEquals([
            new ExposureOverlap(1, 100, $this->d('2026-03-01'), $this->d('2026-05-01')),
            new ExposureOverlap(1, 101, $this->d('2026-03-01'), $this->d('2026-04-10')),
        ], $overlaps);
    }

    public function test_a_female_that_left_before_a_replacement_bull_joined_is_not_exposed(): void
    {
        $overlaps = $this->resolver->overlaps(self::BULL, $this->d('2026-01-01'), [
            $this->stay(1, '2026-04-01', null),
        ], [
            $this->female(1, 100, '2026-03-01', '2026-03-20'),
            $this->female(1, 101, '2026-03-01', null),
        ]);

        $this->assertSame([101], array_map(fn ($o) => $o->femaleId, $overlaps));
        $this->assertEquals($this->d('2026-04-01'), $overlaps[0]->from);
        $this->assertNull($overlaps[0]->until);
    }

    public function test_in_a_controlled_service_only_the_females_assigned_to_the_bull_count(): void
    {
        $overlaps = $this->resolver->overlaps(self::BULL, $this->d('2026-01-01'), [
            $this->stay(1, '2026-03-01', null, controlled: true),
        ], [
            $this->female(1, 100, '2026-03-01', null, assignedTo: self::BULL),
            $this->female(1, 101, '2026-03-01', null, assignedTo: 99),
        ]);

        $this->assertSame([100], array_map(fn ($o) => $o->femaleId, $overlaps));
    }

    public function test_stays_before_the_risk_window_are_left_out_and_older_campaigns_inside_it_count(): void
    {
        $overlaps = $this->resolver->overlaps(self::BULL, $this->d('2025-06-01'), [
            $this->stay(1, '2024-11-01', '2025-02-01'),
            $this->stay(2, '2025-11-01', '2026-02-01'),
        ], [
            $this->female(1, 100, '2024-11-01', '2025-02-01'),
            $this->female(2, 200, '2025-11-01', '2026-02-01'),
        ]);

        $this->assertSame([200], array_map(fn ($o) => $o->femaleId, $overlaps));
    }

    public function test_the_risk_window_starts_at_the_last_negative_or_goes_back_a_year(): void
    {
        $sampling = new VenerealSamplingStatus([
            new SampleResult('TRITRICHOMONAS_FOETUS', 1, $this->d('2025-10-01'), LabSampleStatus::NEGATIVE_CLEARED),
            new SampleResult('TRITRICHOMONAS_FOETUS', 2, $this->d('2026-03-10'), LabSampleStatus::POSITIVE_DETECTED),
            new SampleResult('CAMPYLOBACTER_FETUS', 2, $this->d('2026-03-10'), LabSampleStatus::POSITIVE_DETECTED),
        ]);

        $findings = $this->resolver->contagiousFindings([], $sampling);
        $byCode = [];
        foreach ($findings as $finding) {
            $byCode[$finding->pathogenCode] = $finding;
        }

        $this->assertEquals($this->d('2026-03-10'), $byCode['TRITRICHOMONAS_FOETUS']->detectedOn);
        $this->assertEquals($this->d('2025-10-01'), $byCode['TRITRICHOMONAS_FOETUS']->riskWindowFrom);
        $this->assertTrue($byCode['TRITRICHOMONAS_FOETUS']->riskWindowFromLastNegative);
        $this->assertEquals($this->d('2025-03-10'), $byCode['CAMPYLOBACTER_FETUS']->riskWindowFrom);
        $this->assertFalse($byCode['CAMPYLOBACTER_FETUS']->riskWindowFromLastNegative);
    }

    public function test_a_positive_superseded_by_a_later_negative_raises_nothing(): void
    {
        $sampling = new VenerealSamplingStatus([
            new SampleResult('TRITRICHOMONAS_FOETUS', 1, $this->d('2026-01-10'), LabSampleStatus::POSITIVE_DETECTED),
            new SampleResult('TRITRICHOMONAS_FOETUS', 2, $this->d('2026-02-10'), LabSampleStatus::NEGATIVE_CLEARED),
        ]);

        $this->assertSame([], $this->resolver->contagiousFindings([], $sampling));
    }

    private function stay(int $orderId, string $from, ?string $until, bool $controlled = false): BullParticipation
    {
        return new BullParticipation($orderId, $controlled, $this->d($from), $until !== null ? $this->d($until) : null);
    }

    private function female(int $orderId, int $id, string $from, ?string $until, ?int $assignedTo = null): FemaleExposure
    {
        return new FemaleExposure($orderId, $id, $this->d($from), $until !== null ? $this->d($until) : null, $assignedTo);
    }

    private function d(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date);
    }
}
