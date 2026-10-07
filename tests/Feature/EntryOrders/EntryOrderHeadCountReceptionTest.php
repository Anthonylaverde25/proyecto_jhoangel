<?php

declare(strict_types=1);

namespace Tests\Feature\EntryOrders;

use App\Models\Caravan;

/**
 * The manual reception of a DTE confirms head, not caravans: the DTE declares a count and the
 * person confirms how many arrived. That closes the DTE; a difference is an incident, never a
 * block. Caravans are optional and can be written later, identifying head already received. The
 * order is not finished while some head received have no caravan: it waits for them (RECEIVED,
 * "Recibida · por identificar") and closes with the last one.
 */
class EntryOrderHeadCountReceptionTest extends EntryOrderTestCase
{
    /**
     * @return array<string, mixed> an order of $head males with one DTE declaring them all
     */
    private function orderInTransit(int $head): array
    {
        $order = $this->createOrder(['head_count' => $head, 'sex_composition' => 'MALE', 'male_count' => null, 'female_count' => null])->json('order');

        return $this->loadDte($order['id'], $head);
    }

    /**
     * @param array<string, mixed> $order
     * @param list<array<string, mixed>> $animals
     * @return \Illuminate\Testing\TestResponse
     */
    private function receiveHeads(array $order, int $heads, array $animals = [], ?string $reason = null)
    {
        return $this->receive($order['id'], [
            'dte_id' => $order['dtes'][0]['id'],
            'received_head_count' => $heads,
            'animals' => $animals,
            'reason' => $reason,
        ]);
    }

    public function test_confirming_the_head_of_the_dte_leaves_the_order_to_identify(): void
    {
        $order = $this->orderInTransit(10);

        $received = $this->receiveHeads($order, 10)->assertOk()->json('order');

        // Everything arrived, but no animal is in the system yet: the order is not finished.
        $this->assertSame('RECEIVED', $received['status']);
        $this->assertSame('Recibida · por identificar', $received['status_label']);
        $this->assertTrue($received['is_open']);
        $this->assertFalse($received['accepts_reception']);
        $this->assertNull($received['closed_at']);
        $this->assertSame(10, $received['received_count']);
        $this->assertSame(10, $received['uncaravaned_count']);
        $this->assertSame(0, $received['in_transit_count']);
        $this->assertSame(0, $received['dtes'][0]['caravaned_count']);
        $this->assertSame([], $received['incidents']);
        $this->assertSame(0, Caravan::where('batch_id', $order['batch']['id'])->count());
    }

    public function test_fewer_head_than_the_dte_close_it_with_an_incident(): void
    {
        $order = $this->orderInTransit(10);

        $received = $this->receiveHeads($order, 8, [], 'Dos murieron en el viaje')->assertOk()->json('order');

        // The 8 that arrived are still to identify; once they are, it closes incomplete.
        $this->assertSame('RECEIVED', $received['status']);
        $this->assertSame(0, $received['in_transit_count']);
        $this->assertSame(2, $received['missing_count']);
        $this->assertSame('MISSING_HEAD', $received['incidents'][0]['type']);
        $this->assertStringContainsString('Dos murieron en el viaje', $received['incidents'][0]['detail']);

        $identified = $this->receive($order['id'], ['dte_id' => $order['dtes'][0]['id'], 'animals' => $this->animals('FW', 8)])->assertOk()->json('order');

        $this->assertSame('CLOSED_INCOMPLETE', $identified['status']);
        $this->assertNotNull($identified['closed_at']);
        $this->assertStringContainsString('Dos murieron en el viaje', (string) $identified['closing_reason']);
    }

    public function test_without_a_reason_the_difference_is_still_recorded(): void
    {
        $order = $this->orderInTransit(5);

        $received = $this->receiveHeads($order, 4)->assertOk()->json('order');

        $this->assertSame('MISSING_HEAD', $received['incidents'][0]['type']);
        $this->assertStringContainsString('Se recibieron 4 de 5', $received['incidents'][0]['detail']);
    }

    public function test_more_head_than_the_dte_are_received_with_an_incident(): void
    {
        $order = $this->orderInTransit(3);

        $received = $this->receiveHeads($order, 4)->assertOk()->json('order');

        $this->assertSame(4, $received['received_count']);
        $this->assertSame('ARRIVAL_EXCESS', $received['incidents'][0]['type']);
    }

    public function test_caravans_written_with_the_head_cannot_outnumber_them(): void
    {
        $order = $this->orderInTransit(3);

        $this->receiveHeads($order, 1, $this->animals('HC', 2))
            ->assertStatus(422)
            ->assertJsonPath('header_errors.0.code', 'CARAVANS_EXCEED_HEADS');

        $received = $this->receiveHeads($order, 3, $this->animals('HC', 2))->assertOk()->json('order');

        $this->assertSame(3, $received['received_count']);
        $this->assertSame(1, $received['uncaravaned_count']);
        $this->assertSame(2, Caravan::where('batch_id', $order['batch']['id'])->count());
    }

    public function test_caravans_written_later_identify_the_head_already_received(): void
    {
        $order = $this->orderInTransit(3);
        $this->receiveHeads($order, 3)->assertOk();

        // Nothing more arrives: caravans only identify the head received, never add new ones.
        $this->receive($order['id'], ['dte_id' => $order['dtes'][0]['id'], 'animals' => $this->animals('LT', 4)])
            ->assertStatus(422)
            ->assertJsonPath('header_errors.0.code', 'CARAVANS_EXCEED_UNCARAVANED');

        $identified = $this->receive($order['id'], ['dte_id' => $order['dtes'][0]['id'], 'animals' => $this->animals('LT', 2)])
            ->assertOk()
            ->json('order');

        $this->assertSame('RECEIVED', $identified['status']);
        $this->assertSame(3, $identified['received_count']);
        $this->assertSame(1, $identified['uncaravaned_count']);
        $this->assertSame([], $identified['incidents']);

        // An ING-03 can be issued for the head still without caravan.
        $sheet = $this->apiAs('POST', "/entry-orders/{$order['id']}/receipt-sheets", ['dte_id' => $order['dtes'][0]['id']])
            ->assertSuccessful()
            ->json('order.receipt_sheets.0');
        $this->assertSame(1, $sheet['expected_head_count']);

        // The last caravan finishes the order.
        $done = $this->receive($order['id'], ['dte_id' => $order['dtes'][0]['id'], 'animals' => [['caravana' => 'EO-LT-9']]])->assertOk()->json('order');

        $this->assertSame('COMPLETED', $done['status']);
        $this->assertSame(0, $done['uncaravaned_count']);
        $this->assertNotNull($done['closed_at']);
    }

    public function test_caravans_written_with_every_head_complete_the_order_at_once(): void
    {
        $order = $this->orderInTransit(2);

        $this->assertSame('COMPLETED', $this->receiveHeads($order, 2, $this->animals('AO', 2))->assertOk()->json('order.status'));
    }

    public function test_an_order_closed_by_hand_waits_for_the_caravans_of_what_arrived(): void
    {
        $order = $this->createOrder(['head_count' => 6, 'sex_composition' => 'MALE', 'male_count' => null, 'female_count' => null])->json('order');
        $this->loadDte($order['id'], 3, ['dte_number' => 'DTE-CH-1']);
        $order = $this->loadDte($order['id'], 3, ['dte_number' => 'DTE-CH-2']);
        $this->receive($order['id'], ['dte_id' => $order['dtes'][0]['id'], 'received_head_count' => 3, 'animals' => []])->assertOk();

        // The second DTE will not come; the 3 head of the first still have no caravan.
        $closed = $this->apiAs('POST', "/entry-orders/{$order['id']}/close-incomplete", ['reason' => 'El resto no se manda'])->assertOk()->json();

        $this->assertSame('RECEIVED', $closed['status']);
        $this->assertSame('El resto no se manda', $closed['closing_reason']);

        $identified = $this->receive($order['id'], ['dte_id' => $order['dtes'][0]['id'], 'animals' => $this->animals('CH', 3)])->assertOk()->json('order');

        $this->assertSame('CLOSED_INCOMPLETE', $identified['status']);
        $this->assertSame('El resto no se manda', $identified['closing_reason']);
        $this->assertNotNull($identified['closed_at']);
    }

    public function test_a_dte_already_closed_by_head_takes_no_more_head(): void
    {
        $order = $this->orderInTransit(2);
        $this->receiveHeads($order, 2)->assertOk();

        $this->receiveHeads($order, 1)->assertStatus(422);
    }
}
