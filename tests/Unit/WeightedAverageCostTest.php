<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Services\WeightedAverageCost;
use App\Core\ValueObjects\Decimal;
use PHPUnit\Framework\TestCase;

/**
 * Logical schema 5.4 and plan 4.7, step by step.
 */
class WeightedAverageCostTest extends TestCase
{
    private WeightedAverageCost $costs;

    protected function setUp(): void
    {
        $this->costs = new WeightedAverageCost();
    }

    public function test_entries_weigh_the_average_and_exits_keep_it(): void
    {
        $after = $this->costs->enter(Decimal::of('200'), Decimal::of('280'), Decimal::of('100'), Decimal::of('311.08'));
        $this->assertSame(['300.0000', '290.360000'], $this->pair($after));

        $after = $this->costs->leave($after['balance'], $after['average'], Decimal::of('150'));
        $this->assertSame(['150.0000', '290.360000'], $this->pair($after));
    }

    public function test_entering_over_nothing_or_a_negative_balance_takes_the_entry_cost(): void
    {
        $this->assertSame(['100.0000', '10.000000'], $this->pair($this->costs->enter(Decimal::zero(), Decimal::zero(), Decimal::of('100'), Decimal::of('10'))));
        $this->assertSame(['75.0000', '10.000000'], $this->pair($this->costs->enter(Decimal::of('-25'), Decimal::of('24'), Decimal::of('100'), Decimal::of('10'))));
    }

    public function test_undoing_an_entry_takes_it_out_at_its_original_cost(): void
    {
        // 100 at 10 and 100 at 30 (average 20); undoing the second leaves 100 at 10.
        $after = $this->costs->undoEntry(Decimal::of('200'), Decimal::of('20'), Decimal::of('100'), Decimal::of('30'));
        $this->assertSame(['100.0000', '10.000000'], $this->pair($after));

        // Nothing left (or less): the average stays.
        $after = $this->costs->undoEntry(Decimal::of('50'), Decimal::of('20'), Decimal::of('100'), Decimal::of('30'));
        $this->assertSame(['-50.0000', '20.000000'], $this->pair($after));
    }

    public function test_undoing_an_entry_is_refused_when_what_remains_would_be_worth_less_than_nothing(): void
    {
        // Plan 4.7: 100 ml at 100, 90 ml consumed, 200 ml at 1 → 210 ml worth 1.200; undo the first.
        $balance = $this->costs->enter(Decimal::of('10'), Decimal::of('100'), Decimal::of('200'), Decimal::of('1'));
        $this->assertSame(['210.0000', '5.714286'], $this->pair($balance));

        $this->assertNull($this->costs->undoEntry($balance['balance'], $balance['average'], Decimal::of('100'), Decimal::of('100')));
    }

    /** @param array{balance: Decimal, average: Decimal} $state */
    private function pair(array $state): array
    {
        return [$state['balance']->toScale(4), $state['average']->toScale(6)];
    }
}
