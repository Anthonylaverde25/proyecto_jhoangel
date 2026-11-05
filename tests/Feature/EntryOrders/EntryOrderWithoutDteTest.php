<?php

declare(strict_types=1);

namespace Tests\Feature\EntryOrders;

use App\Models\Caravan;
use App\Models\EntryOrder;
use App\Models\CaravanMovement;
use App\Models\EntryOrderIncident;
use Database\Seeders\EntryOrderTestSeeder;

/**
 * The DTE is recommended, never required: head move without it, marked "Sin DTE", and the operation
 * goes on as usual until the paper is loaded, even after the order closed.
 */
class EntryOrderWithoutDteTest extends EntryOrderTestCase
{
    /** @return array<string, mixed> */
    private function moveWithoutDte(int $orderId, int $headCount): array
    {
        return $this->apiAs('POST', "/entry-orders/{$orderId}/dtes", ['without_dte' => true, 'head_count' => $headCount])
            ->assertCreated()
            ->json('order');
    }

    /** @return array<string, mixed> */
    private function attach(int $orderId, int $dteId, int $headCount, string $number = 'DTE-LATE-1'): array
    {
        return $this->apiAs('PUT', "/entry-orders/{$orderId}/dtes/{$dteId}/document", [
            'dte_number' => $number,
            'dte_date' => now()->toDateString(),
            'head_count' => $headCount,
        ])->assertOk()->json('order');
    }

    public function test_the_number_is_still_required_unless_going_without_dte_is_said(): void
    {
        $order = $this->createOrder()->json('order');

        $this->apiAs('POST', "/entry-orders/{$order['id']}/dtes", ['head_count' => 40, 'dte_date' => now()->toDateString()])
            ->assertStatus(422)
            ->assertJsonValidationErrors('dte_number');
    }

    public function test_head_move_without_dte_and_the_order_stops_waiting_for_it(): void
    {
        $order = $this->createOrder()->json('order');

        $moved = $this->moveWithoutDte($order['id'], 40);

        $this->assertSame('IN_TRANSIT', $moved['status']);
        $this->assertTrue($moved['without_dte']);
        $this->assertSame(40, $moved['without_dte_head_count']);
        $this->assertSame(0, $moved['with_dte_count']);
        $this->assertSame(40, $moved['pending_dte_count']);
        $this->assertSame(0, $moved['undeclared_head_count']);
        $this->assertSame(40, $moved['in_transit_count']);
        $this->assertSame(0, $moved['dte_count']);
        $this->assertNull($moved['dtes'][0]['dte_number']);
        $this->assertNull($moved['dtes'][0]['dte_date']);
        $this->assertNull($moved['first_dte_at']);
    }

    public function test_part_of_the_head_without_dte_leaves_the_rest_waiting(): void
    {
        $order = $this->createOrder()->json('order');

        $moved = $this->moveWithoutDte($order['id'], 30);

        $this->assertSame('AWAITING_DTE', $moved['status']);
        $this->assertSame(10, $moved['undeclared_head_count']);
        $this->assertTrue($moved['accepts_dte']);

        $second = $this->moveWithoutDte($order['id'], 10);

        $this->assertSame('IN_TRANSIT', $second['status']);
        $this->assertCount(2, $second['dtes']);
    }

    public function test_head_without_dte_are_received_like_any_other_and_the_mark_stays(): void
    {
        $order = $this->createOrder(['head_count' => 3, 'sex_composition' => 'MALE', 'male_count' => null, 'female_count' => null])->json('order');
        $moved = $this->moveWithoutDte($order['id'], 3);

        $received = $this->receive($order['id'], [
            'method' => 'MANUAL',
            'received_at' => now()->toDateString(),
            'dte_id' => $moved['dtes'][0]['id'],
            'animals' => $this->animals('SD', 3),
        ])->assertOk()->json('order');

        $this->assertSame('COMPLETED', $received['status']);
        $this->assertTrue($received['without_dte']);
        $this->assertSame(3, $received['received_count']);

        $caravan = Caravan::where('identification', 'EO-SD-1')->firstOrFail();
        $this->assertNull($caravan->provenance_metadata['dte_number'] ?? null);
        $this->assertSame(
            "Ingreso sin DTE de la orden {$order['code']}",
            CaravanMovement::where('caravan_id', $caravan->id)->value('observations')
        );
    }

    public function test_a_head_count_reception_and_its_ing03_work_without_dte(): void
    {
        $order = $this->createOrder(['head_count' => 3, 'sex_composition' => 'MALE', 'male_count' => null, 'female_count' => null])->json('order');
        $dteId = $this->moveWithoutDte($order['id'], 3)['dtes'][0]['id'];

        $received = $this->receive($order['id'], [
            'method' => 'MANUAL',
            'received_at' => now()->toDateString(),
            'dte_id' => $dteId,
            'animals' => [],
            'received_head_count' => 3,
        ])->assertOk()->json('order');

        $this->assertSame('RECEIVED', $received['status']);

        $issued = $this->apiAs('POST', "/entry-orders/{$order['id']}/receipt-sheets", ['dte_id' => $dteId])
            ->assertSuccessful()
            ->json('order');

        $this->assertNull($issued['receipt_sheets'][0]['dte_number']);
    }

    public function test_the_paper_attached_later_reaches_the_caravans_and_clears_the_mark(): void
    {
        $order = $this->createOrder(['head_count' => 2, 'sex_composition' => 'MALE', 'male_count' => null, 'female_count' => null])->json('order');
        $dteId = $this->moveWithoutDte($order['id'], 2)['dtes'][0]['id'];
        $this->receive($order['id'], [
            'method' => 'MANUAL',
            'received_at' => now()->toDateString(),
            'dte_id' => $dteId,
            'animals' => $this->animals('LT', 2),
        ])->assertOk();

        // The order is already closed: the paper is still welcome.
        $attached = $this->attach($order['id'], $dteId, 2);

        $this->assertSame('COMPLETED', $attached['status']);
        $this->assertFalse($attached['without_dte']);
        $this->assertSame(2, $attached['with_dte_count']);
        $this->assertSame(1, $attached['dte_count']);
        $this->assertSame('DTE-LATE-1', $attached['dtes'][0]['dte_number']);
        $this->assertNotNull($attached['first_dte_at']);

        $caravan = Caravan::where('identification', 'EO-LT-1')->firstOrFail();
        $this->assertSame('DTE-LATE-1', $caravan->provenance_metadata['dte_number']);
        $this->assertSame(
            "Ingreso por DTE DTE-LATE-1 de la orden {$order['code']}",
            CaravanMovement::where('caravan_id', $caravan->id)->value('observations')
        );
    }

    public function test_a_paper_declaring_fewer_head_than_received_raises_an_excess(): void
    {
        $order = $this->createOrder(['head_count' => 3, 'sex_composition' => 'MALE', 'male_count' => null, 'female_count' => null])->json('order');
        $dteId = $this->moveWithoutDte($order['id'], 3)['dtes'][0]['id'];
        $this->receive($order['id'], [
            'method' => 'MANUAL',
            'received_at' => now()->toDateString(),
            'dte_id' => $dteId,
            'animals' => $this->animals('EX', 3),
        ])->assertOk();

        $attached = $this->attach($order['id'], $dteId, 2);

        $this->assertSame('COMPLETED', $attached['status']);
        $this->assertSame(1, EntryOrderIncident::where('entry_order_id', $order['id'])->where('type', 'ARRIVAL_EXCESS')->count());
        $this->assertSame(1, $attached['open_incidents_count']);
    }

    public function test_a_paper_declaring_more_head_on_a_closed_order_leaves_them_as_missing(): void
    {
        $order = $this->createOrder(['head_count' => 2, 'sex_composition' => 'MALE', 'male_count' => null, 'female_count' => null])->json('order');
        $dteId = $this->moveWithoutDte($order['id'], 2)['dtes'][0]['id'];
        $this->receive($order['id'], [
            'method' => 'MANUAL',
            'received_at' => now()->toDateString(),
            'dte_id' => $dteId,
            'animals' => $this->animals('MS', 2),
        ])->assertOk();

        $attached = $this->attach($order['id'], $dteId, 3);

        $this->assertSame('CLOSED_INCOMPLETE', $attached['status']);
        $this->assertSame(1, $attached['missing_count']);
        $this->assertSame(0, $attached['in_transit_count']);
        $this->assertSame(1, EntryOrderIncident::where('entry_order_id', $order['id'])->where('type', 'MISSING_HEAD')->count());
        $this->assertSame(1, EntryOrderIncident::where('entry_order_id', $order['id'])->where('type', 'EXCESS_HEAD')->count());
    }

    public function test_a_paper_declaring_more_head_while_receiving_leaves_them_in_transit(): void
    {
        $order = $this->createOrder()->json('order');
        $dteId = $this->moveWithoutDte($order['id'], 30)['dtes'][0]['id'];

        $attached = $this->attach($order['id'], $dteId, 40);

        $this->assertSame('IN_TRANSIT', $attached['status']);
        $this->assertSame(40, $attached['in_transit_count']);
        $this->assertSame(40, $attached['with_dte_count']);
        $this->assertSame(0, $attached['open_incidents_count']);
    }

    public function test_a_number_already_used_or_a_row_with_its_paper_is_refused(): void
    {
        $order = $this->createOrder()->json('order');
        $this->loadDte($order['id'], 10, ['dte_number' => 'DTE-TAKEN']);
        $moved = $this->moveWithoutDte($order['id'], 30);
        $withoutPaper = collect($moved['dtes'])->firstWhere('dte_number', null)['id'];
        $withPaper = collect($moved['dtes'])->firstWhere('dte_number', 'DTE-TAKEN')['id'];

        $this->apiAs('PUT', "/entry-orders/{$order['id']}/dtes/{$withoutPaper}/document", [
            'dte_number' => 'dte-taken',
            'dte_date' => now()->toDateString(),
            'head_count' => 30,
        ])->assertStatus(422);

        $this->apiAs('PUT', "/entry-orders/{$order['id']}/dtes/{$withPaper}/document", [
            'dte_number' => 'DTE-OTHER',
            'dte_date' => now()->toDateString(),
            'head_count' => 10,
        ])->assertStatus(422)->assertJsonPath('code', 'DTE_ALREADY_ATTACHED');
    }

    public function test_two_orders_can_wait_for_their_paper_at_once(): void
    {
        $first = $this->createOrder()->json('order');
        $second = $this->createOrder()->json('order');

        $this->assertTrue($this->moveWithoutDte($first['id'], 40)['without_dte']);
        $this->assertTrue($this->moveWithoutDte($second['id'], 40)['without_dte']);
    }

    public function test_an_entry_can_be_registered_without_dte(): void
    {
        $order = $this->apiAs('POST', '/entry-orders/register', [
            ...$this->troop(['head_count' => 2, 'sex_composition' => 'MALE', 'male_count' => null, 'female_count' => null]),
            'dte' => [
                'without_dte' => true,
                'head_count' => 2,
                'entered_at' => now()->toDateString(),
                'animals' => $this->animals('RG', 2),
            ],
        ])->assertCreated()->json('order');

        $this->assertSame('COMPLETED', $order['status']);
        $this->assertTrue($order['without_dte']);
        $this->assertSame(2, $order['received_count']);
        $this->assertNull($order['dtes'][0]['dte_number']);
        $this->assertSame(2, Caravan::whereIn('identification', ['EO-RG-1', 'EO-RG-2'])->count());
    }

    public function test_the_seeded_order_shows_its_mark_and_can_be_received(): void
    {
        (new EntryOrderTestSeeder())->run();

        $seeded = EntryOrder::where('code', 'EN-20260928-0018')->firstOrFail();
        $order = $this->apiAs('GET', "/entry-orders/{$seeded->id}")->assertOk()->json();

        $this->assertSame('IN_TRANSIT', $order['status']);
        $this->assertTrue($order['without_dte']);
        $this->assertSame(6, $order['without_dte_head_count']);
        $this->assertNull($order['receipt_sheets'][0]['dte_number']);
    }

    public function test_closing_incomplete_with_head_without_dte_declares_them_missing_not_as_never_documented(): void
    {
        $order = $this->createOrder()->json('order');
        $this->moveWithoutDte($order['id'], 40);

        $closed = $this->apiAs('POST', "/entry-orders/{$order['id']}/close-incomplete", ['reason' => 'No vinieron'])
            ->assertOk()
            ->json();

        $this->assertSame('CLOSED_INCOMPLETE', $closed['status']);
        $this->assertSame(40, $closed['missing_count']);
        $this->assertSame(0, EntryOrderIncident::where('entry_order_id', $order['id'])->where('type', 'MISSING_DTE')->count());
    }
}
