<?php

declare(strict_types=1);

namespace Tests\Feature\EntryOrders;

use Tests\Feature\DryRun\AssertsDryRun;
use App\Models\Batch;
use App\Models\Caravan;
use App\Models\EntryOrderIncident;

/**
 * A DTE declares how many head move: loading it creates no caravan. Its head are in transit until
 * their animals are received, and once every head bought has its DTE the order waits for animals.
 */
class EntryOrderDteTest extends EntryOrderTestCase
{
    use AssertsDryRun;

    public function test_loading_a_dte_declares_head_in_transit_and_creates_no_caravan(): void
    {
        $order = $this->createOrder()->json('order');
        $caravans = Caravan::count();

        $loaded = $this->loadDte($order['id'], 30, ['dte_number' => '012345678']);

        $this->assertSame('AWAITING_DTE', $loaded['status']);
        $this->assertSame(30, $loaded['with_dte_count']);
        $this->assertSame(10, $loaded['pending_dte_count']);
        $this->assertSame(30, $loaded['in_transit_count']);
        $this->assertSame(0, $loaded['received_count']);

        $dte = $loaded['dtes'][0];
        $this->assertSame('012345678', $dte['dte_number']);
        $this->assertSame(30, $dte['head_count']);
        $this->assertSame(30, $dte['pending_count']);
        $this->assertSame(0, $dte['received_count']);
        $this->assertSame(0, $dte['missing_head_count']);
        $this->assertSame([], $dte['animals']);

        $this->assertSame($caravans, Caravan::count());
        $this->assertSame(0, (int) Batch::findOrFail($order['batch']['id'])->caravans_count);
    }

    public function test_a_dry_run_of_a_new_order_creates_nothing(): void
    {
        $this->assertDryRunLeavesNothing(
            ['entry_orders', 'batches', 'entry_order_breeds', 'entry_order_histories'],
            fn () => $this->createOrder(['auction_number' => '901']),
            201
        );

        $this->createOrder(['auction_number' => '901'])->assertCreated();
    }

    public function test_the_order_moves_to_in_transit_once_every_head_has_its_dte(): void
    {
        $order = $this->createOrder()->json('order');

        $this->loadDte($order['id'], 30);
        $loaded = $this->loadDte($order['id'], 10);

        $this->assertSame('IN_TRANSIT', $loaded['status']);
        $this->assertSame(0, $loaded['pending_dte_count']);
        $this->assertSame(40, $loaded['in_transit_count']);
        $this->assertCount(2, $loaded['dtes']);
        $this->assertNull($loaded['closed_at']);
        $this->assertFalse($loaded['accepts_dte']);
        $this->assertTrue($loaded['accepts_reception']);
        $this->assertTrue($loaded['can_correct_dtes']);

        $this->apiAs('POST', "/entry-orders/{$order['id']}/dtes", $this->dte(1))
            ->assertStatus(422)
            ->assertJsonPath('code', 'ENTRY_ORDER_NOT_ACCEPTING_DTE');
    }

    public function test_more_head_than_bought_is_loaded_and_raises_an_incident(): void
    {
        $order = $this->createOrder(['head_count' => 2, 'sex_composition' => 'MALE', 'male_count' => null, 'female_count' => null])->json('order');

        $response = $this->apiAs('POST', "/entry-orders/{$order['id']}/dtes", $this->dte(4, ['dte_number' => 'DTE-EXC']))
            ->assertCreated();
        $loaded = $response->json('order');

        $this->assertSame('IN_TRANSIT', $loaded['status']);
        $this->assertSame(2, $loaded['head_count']);
        $this->assertSame(4, $loaded['with_dte_count']);
        $this->assertSame(4, $loaded['in_transit_count']);
        $this->assertSame(1, $loaded['open_incidents_count']);
        $this->assertSame('EXCESS_HEAD', $loaded['incidents'][0]['type']);
        $this->assertSame('DTE-EXC', $loaded['incidents'][0]['dte_number']);
        $this->assertStringContainsString('(+2)', $loaded['incidents'][0]['detail']);
        $this->assertContains('EXCESS_HEAD', array_column($response->json('warnings'), 'code'));
    }

    public function test_the_same_dte_cannot_be_loaded_twice(): void
    {
        $first = $this->createOrder()->json('order');
        $second = $this->createOrder(['auction_number' => '339'])->json('order');

        $this->loadDte($first['id'], 1, ['dte_number' => 'dte-777']);

        $response = $this->apiAs('POST', "/entry-orders/{$second['id']}/dtes", $this->dte(1, ['dte_number' => 'DTE-777']))
            ->assertStatus(422);

        $this->assertSame('DTE_ALREADY_LOADED', $response->json('header_errors.0.code'));
        $this->assertStringContainsString($first['code'], $response->json('header_errors.0.message'));
    }

    public function test_the_dte_date_cannot_be_future_or_before_the_purchase_and_needs_head(): void
    {
        $order = $this->createOrder()->json('order');

        $this->apiAs('POST', "/entry-orders/{$order['id']}/dtes", $this->dte(1, ['dte_date' => now()->addDay()->toDateString()]))
            ->assertStatus(422);

        $response = $this->apiAs('POST', "/entry-orders/{$order['id']}/dtes", $this->dte(1, [
            'dte_date' => now()->subDays(5)->toDateString(),
        ]))->assertStatus(422);
        $this->assertSame('DTE_BEFORE_PURCHASE', $response->json('header_errors.0.code'));

        $this->apiAs('POST', "/entry-orders/{$order['id']}/dtes", $this->dte(0))
            ->assertStatus(422)
            ->assertJsonValidationErrors('head_count');
    }

    public function test_an_order_with_a_dte_is_not_cancelled_but_closed_incomplete(): void
    {
        $order = $this->createOrder(['head_count' => 3, 'male_count' => 2, 'female_count' => 1])->json('order');
        $loaded = $this->loadDte($order['id'], 2, ['dte_number' => 'DTE-SHORT']);

        $this->assertFalse($loaded['can_cancel']);
        $this->assertTrue($loaded['can_close_incomplete']);

        $this->apiAs('POST', "/entry-orders/{$order['id']}/cancel", ['reason' => 'No vino'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'INVALID_STATE_TRANSITION');

        $closed = $this->apiAs('POST', "/entry-orders/{$order['id']}/close-incomplete", ['reason' => 'El vendedor no manda la ternera'])
            ->assertOk()
            ->json();

        // The two head of the DTE were still in transit: they will not arrive either. The heifer
        // that never got a DTE is also left to settle with the seller.
        $this->assertSame('CLOSED_INCOMPLETE', $closed['status']);
        $this->assertSame(2, $closed['missing_count']);
        $this->assertSame(2, $closed['dtes'][0]['missing_head_count']);
        $this->assertSame(0, $closed['in_transit_count']);
        $this->assertSame(['MISSING_HEAD', 'MISSING_DTE'], array_column($closed['incidents'], 'type'));
        $this->assertSame('2 cabezas del DTE DTE-SHORT no llegarán. Motivo: El vendedor no manda la ternera', $closed['incidents'][0]['detail']);
        $this->assertStringContainsString('1 cabeza nunca tuvo DTE', $closed['incidents'][1]['detail']);

        $this->apiAs('POST', "/entry-orders/{$order['id']}/dtes", $this->dte(1))
            ->assertStatus(422)
            ->assertJsonPath('code', 'ENTRY_ORDER_NOT_ACCEPTING_DTE');
    }

    public function test_an_order_without_dte_is_cancelled_not_closed_incomplete(): void
    {
        $order = $this->createOrder()->json('order');

        $this->apiAs('POST', "/entry-orders/{$order['id']}/close-incomplete", ['reason' => 'No se hizo'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'INVALID_STATE_TRANSITION');
    }

    public function test_the_incidents_of_a_dte_point_to_it(): void
    {
        $order = $this->createOrder(['head_count' => 1, 'sex_composition' => 'MALE', 'male_count' => null, 'female_count' => null])->json('order');
        $loaded = $this->loadDte($order['id'], 3);

        $this->assertSame(
            $loaded['dtes'][0]['id'],
            (int) EntryOrderIncident::where('entry_order_id', $order['id'])->value('entry_order_dte_id')
        );
    }
}
