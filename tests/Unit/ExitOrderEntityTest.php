<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Entities\ExitOrderEntity;
use App\Core\Enums\ExitOrderKind;
use App\Core\Enums\ExitOrderStatus;
use App\Core\Exceptions\ExitOrderDomainException;
use App\Core\ValueObjects\ExitSnapshot;
use PHPUnit\Framework\TestCase;

/**
 * The status follows the roll (01 §3) and the amount follows weight and price (E7).
 */
class ExitOrderEntityTest extends TestCase
{
    /** @param list<int> $ids */
    private function order(array $ids = [1, 2]): ExitOrderEntity
    {
        return ExitOrderEntity::create(1, 'EG-20261028-0001', ExitOrderKind::PLANNED, 9, '2026-10-28', $ids, null, null, null);
    }

    /** @return array{snapshot: ExitSnapshot, weight_kg: ?float, price_per_kg: ?float, amount: ?float, movement_id: int, weight_id: ?int} */
    private function exit(?float $kg = null, ?float $price = null, ?float $amount = null): array
    {
        return ['snapshot' => new ExitSnapshot(5, 2, 3, false), 'weight_kg' => $kg, 'price_per_kg' => $price, 'amount' => $amount, 'movement_id' => 7, 'weight_id' => null];
    }

    public function test_an_empty_or_repeated_roll_is_refused(): void
    {
        $this->expectException(ExitOrderDomainException::class);
        $this->order([]);
    }

    public function test_repeated_animals_are_refused(): void
    {
        $this->expectException(ExitOrderDomainException::class);
        $this->order([1, 1]);
    }

    public function test_the_status_follows_the_roll(): void
    {
        $order = $this->order([1, 2, 3]);

        $order->recordExits('2026-10-28', [1 => $this->exit()]);
        $this->assertSame(ExitOrderStatus::PARTIAL, $order->getStatus());

        $order->skipByDeath(2, '2026-10-28', 'Neumonía', 'AR-2');
        $this->assertSame(ExitOrderStatus::PARTIAL, $order->getStatus());

        $order->recordExits('2026-10-28', [3 => $this->exit()]);
        $this->assertSame(ExitOrderStatus::CLOSED_INCOMPLETE, $order->getStatus());
        $this->assertSame('Murió el 28/10 · Neumonía', $order->getClosingReason());
    }

    public function test_all_out_is_executed(): void
    {
        $order = $this->order();
        $order->recordExits('2026-10-28', [1 => $this->exit(), 2 => $this->exit()]);

        $this->assertSame(ExitOrderStatus::EXECUTED, $order->getStatus());
        $this->assertTrue($order->isWithoutDte());
    }

    public function test_the_amount_is_weight_times_price_or_the_typed_one(): void
    {
        $order = $this->order();
        $order->recordExits('2026-10-28', [1 => $this->exit(333.33, 1999.99, 5), 2 => $this->exit(null, 2000.0, 800000.0)]);

        $this->assertSame(666656.67, $order->getAnimals()[0]->getAmount());
        $this->assertSame(800000.0, $order->getAnimals()[1]->getAmount());
        $this->assertSame(1466656.67, $order->totalAmount());
    }

    public function test_cancel_needs_nothing_out_and_close_needs_something_out(): void
    {
        $order = $this->order();

        try {
            $order->closeIncomplete('No subió');
            $this->fail('An issued order closed incomplete.');
        } catch (ExitOrderDomainException) {
        }

        $order->recordExits('2026-10-28', [1 => $this->exit()]);

        $this->expectException(ExitOrderDomainException::class);
        $order->cancel('Error');
    }
}
