<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Entities\CaravanWeightEntity;
use App\Core\Services\WeighingSanityPolicy;
use PHPUnit\Framework\TestCase;

/** The same limit cases as weighingSanity.ts: 15–1200 kg and −1.5 to +2.5 kg/day. */
final class WeighingSanityPolicyTest extends TestCase
{
    private WeighingSanityPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new WeighingSanityPolicy();
    }

    private function previous(float $weight, string $date): CaravanWeightEntity
    {
        return new CaravanWeightEntity(id: 1, caravanId: 1, weight: $weight, current: true, weighingDate: new \DateTimeImmutable($date));
    }

    public function test_the_physical_limits_are_inclusive(): void
    {
        $this->assertSame([], $this->policy->warnings(15, '2026-10-01', null));
        $this->assertSame([], $this->policy->warnings(1200, '2026-10-01', null));
        $this->assertCount(1, $this->policy->warnings(14.9, '2026-10-01', null));
        $this->assertCount(1, $this->policy->warnings(1200.1, '2026-10-01', null));
    }

    public function test_the_daily_rates_are_inclusive(): void
    {
        $previous = $this->previous(300, '2026-10-01');

        $this->assertSame([], $this->policy->warnings(325, '2026-10-11', $previous));
        $this->assertSame([], $this->policy->warnings(285, '2026-10-11', $previous));
        $this->assertCount(1, $this->policy->warnings(325.1, '2026-10-11', $previous));
        $this->assertCount(1, $this->policy->warnings(284.9, '2026-10-11', $previous));
    }

    public function test_the_message_reports_the_change_since_the_previous_weighing(): void
    {
        [$warning] = $this->policy->warnings(810, '2026-11-02', $this->previous(295, '2026-10-20'));

        $this->assertSame('Pesó 295 kg el 20/10/2026 y ahora 810 kg: +39,6 kg/día. Revisá la lectura.', $warning);
    }

    public function test_a_previous_weighing_of_the_same_day_has_no_rate(): void
    {
        $this->assertNull(WeighingSanityPolicy::dailyChange(400, '2026-10-01', $this->previous(300, '2026-10-01')));
        $this->assertSame([], $this->policy->warnings(400, '2026-10-01', $this->previous(300, '2026-10-01')));
    }

    public function test_an_impossible_weight_and_a_sudden_change_are_both_reported(): void
    {
        $this->assertCount(2, $this->policy->warnings(1500, '2026-10-11', $this->previous(300, '2026-10-01')));
    }
}
