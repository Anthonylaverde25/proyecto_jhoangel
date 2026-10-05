<?php

declare(strict_types=1);

namespace Tests\Feature\EntryOrders;

use App\Models\Batch;
use App\Models\Caravan;
use App\Models\EntryOrder;

/**
 * "Registrar ingreso": the DTE and the animals are already in hand, so the order is created with
 * its DTE and every caravan received on the entry day, all or nothing.
 */
class RegisterEntryTest extends EntryOrderTestCase
{
    public function test_an_entry_with_its_dte_is_born_completed(): void
    {
        $response = $this->apiAs('POST', '/entry-orders/register', [
            ...$this->troop(['head_count' => 3, 'male_count' => 2, 'female_count' => 1]),
            'dte' => $this->registerDte([
                ...$this->animals('R', 2, 'M', 1, 185),
                ...$this->animals('Q', 1, 'H', 2, null),
            ]),
        ])->assertCreated();

        $order = $response->json('order');
        $this->assertSame('COMPLETED', $order['status']);
        $this->assertSame('REGISTERED', $order['kind']);
        $this->assertSame(3, $order['received_count']);
        $this->assertSame(3, Caravan::where('batch_id', $order['batch']['id'])->count());
        $this->assertSame(3, (int) Batch::findOrFail($order['batch']['id'])->caravans_count);
        $this->assertSame(['MANUAL'], array_values(array_unique(array_column($order['dtes'][0]['animals'], 'reception_method'))));
        $this->assertEquals(185.0, (float) Caravan::where('identification', 'EO-R-1')->value('entry_weight'));
        $this->assertSame(now()->toDateString(), Caravan::where('identification', 'EO-Q-1')->firstOrFail()->entry_date->toDateString());
        $this->assertCount(1, $order['history']);
    }

    public function test_a_short_dte_can_close_the_order_incomplete_at_once(): void
    {
        $order = $this->apiAs('POST', '/entry-orders/register', [
            ...$this->troop(['head_count' => 3, 'male_count' => 2, 'female_count' => 1]),
            'dte' => $this->registerDte($this->animals('W', 2, 'M', 1)),
            'close_incomplete_reason' => 'Faltó una hembra',
        ])->assertCreated()->json('order');

        $this->assertSame('CLOSED_INCOMPLETE', $order['status']);
        $this->assertSame('Faltó una hembra', $order['closing_reason']);
        $this->assertSame(2, $order['received_count']);
        $this->assertSame(0, $order['missing_count']);
        $this->assertSame(['MISSING_DTE'], array_column($order['incidents'], 'type'));
    }

    public function test_a_failing_row_leaves_nothing_behind(): void
    {
        $batches = Batch::count();
        $orders = EntryOrder::count();

        $this->apiAs('POST', '/entry-orders/register', [
            ...$this->troop(['head_count' => 2, 'male_count' => 1, 'female_count' => 1]),
            'dte' => $this->registerDte([
                ['caravana' => 'EO-OK', 'sex' => 'M', 'breed_position' => 1, 'weight' => 180],
                ['caravana' => 'EO-BAD', 'sex' => 'X', 'breed_position' => 1, 'weight' => 180],
            ]),
        ])->assertStatus(422)->assertJsonPath('row_errors.0.code', 'SEX_INVALID');

        $this->assertSame($orders, EntryOrder::count());
        $this->assertSame($batches, Batch::count());
        $this->assertSame(0, Caravan::where('identification', 'EO-OK')->count());
    }
}
