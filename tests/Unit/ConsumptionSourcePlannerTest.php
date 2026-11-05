<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Services\ConsumptionSourcePlanner;
use App\Core\Services\StockIssueSplit;
use App\Core\Services\WeightedAverageCost;
use App\Core\ValueObjects\ConsumptionSourceOption;
use App\Core\ValueObjects\Decimal;
use App\Core\ValueObjects\WarehouseBalance;
use PHPUnit\Framework\TestCase;

class ConsumptionSourcePlannerTest extends TestCase
{
    private function balance(int $id, string $quantity, string $unvalued = '0', string $average = '100'): WarehouseBalance
    {
        return new WarehouseBalance($id, "W{$id}", "Almacén {$id}", Decimal::of($quantity), Decimal::of($unvalued), Decimal::of($average));
    }

    /** @param list<WarehouseBalance> $balances @return list<ConsumptionSourceOption> */
    private function plan(array $balances, string $quantity): array
    {
        return (new ConsumptionSourcePlanner(new WeightedAverageCost()))->plan($balances, 1, Decimal::of($quantity));
    }

    public function test_the_fullest_warehouse_completes_first(): void
    {
        $options = $this->plan([$this->balance(1, '1'), $this->balance(2, '1'), $this->balance(3, '5')], '4');

        $this->assertSame(3, $options[1]->transfers[0]['from']->warehouseId);
        $this->assertSame('3.0000', $options[1]->transfers[0]['quantity']->toScale(4));
        $this->assertCount(1, $options[1]->transfers);
    }

    public function test_several_warehouses_complete_when_one_is_not_enough(): void
    {
        $options = $this->plan([$this->balance(1, '0'), $this->balance(2, '2'), $this->balance(3, '2')], '3');

        $this->assertSame(['BASE', 'COMPLETE'], array_map(fn ($o) => $o->code, $options));
        $this->assertSame(['2.0000', '1.0000'], array_map(fn ($t) => $t['quantity']->toScale(4), $options[1]->transfers));
        $this->assertSame('0.0000', $options[1]->balanceAfter->toScale(4));
    }

    public function test_a_negative_default_does_not_ask_the_others_to_cover_its_debt(): void
    {
        $options = $this->plan([$this->balance(1, '-11'), $this->balance(2, '5')], '3');

        $this->assertSame('3.0000', $options[1]->transfers[0]['quantity']->toScale(4));
        $this->assertSame('-11.0000', $options[1]->balanceAfter->toScale(4));
    }

    public function test_completing_estimates_at_the_average_after_the_transfer(): void
    {
        $options = $this->plan([$this->balance(1, '1', average: '100'), $this->balance(2, '3', average: '200')], '3');

        // (1 x 100 + 2 x 200) / 3 = 166.666667, times 3
        $this->assertSame('500.0000', $options[1]->estimatedTotal->toScale(4));
    }

    public function test_an_average_of_zero_is_an_unknown_cost(): void
    {
        $options = $this->plan([$this->balance(1, '5', average: '0')], '3');

        $this->assertFalse($options[0]->costKnown);
    }

    public function test_units_waiting_for_their_invoice_are_pending_and_not_unknown(): void
    {
        $options = $this->plan([$this->balance(1, '5', unvalued: '5', average: '0')], '3');

        $this->assertTrue($options[0]->costKnown);
        $this->assertSame('3.0000', $options[0]->pendingQuantity->toScale(4));
    }

    public function test_the_split_takes_the_valued_units_first(): void
    {
        $pending = StockIssueSplit::pending(Decimal::of('5'), Decimal::of('3'), Decimal::of('4'));

        $this->assertSame('2.0000', $pending->toScale(4));
    }
}
