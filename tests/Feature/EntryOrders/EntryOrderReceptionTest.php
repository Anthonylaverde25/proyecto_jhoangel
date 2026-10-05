<?php

declare(strict_types=1);

namespace Tests\Feature\EntryOrders;

use App\Models\Batch;
use App\Models\Caravan;
use App\Models\CaravanMovement;
use App\Models\CaravanWeight;
use App\Models\EntryOrderAnimal;

/**
 * Receiving the animals of a DTE: by hand on one DTE, maybe in parts, or read at the chute across
 * DTEs. A received caravan comes into possession; one declared missing never will; one left out
 * stays in transit.
 */
class EntryOrderReceptionTest extends EntryOrderTestCase
{
    public function test_a_manual_reception_of_the_whole_dte_completes_the_order(): void
    {
        $order = $this->createOrder(['head_count' => 3, 'male_count' => 2, 'female_count' => 1])->json('order');
        $loaded = $this->loadDte($order['id'], [...$this->animals('R', 2, 'M', 1), ...$this->animals('H', 1, 'H', 1)]);
        $ids = $this->caravanIds($loaded);

        $received = $this->receive($order['id'], [
            'method' => 'MANUAL',
            'dte_id' => $loaded['dtes'][0]['id'],
            'received' => [['caravan_id' => $ids[0], 'weight' => 182.5], ['caravan_id' => $ids[1], 'weight' => null], ['caravan_id' => $ids[2]]],
        ])->assertOk()->json('order');

        $this->assertSame('COMPLETED', $received['status']);
        $this->assertSame(3, $received['received_count']);
        $this->assertSame(0, $received['in_transit_count']);
        $this->assertNotNull($received['closed_at']);
        $this->assertSame('MANUAL', $received['dtes'][0]['animals'][0]['reception_method']);
        $this->assertSame(now()->toDateString(), $received['dtes'][0]['animals'][0]['received_at']);

        $caravan = Caravan::findOrFail($ids[0]);
        $this->assertSame(now()->toDateString(), $caravan->entry_date->toDateString());
        $this->assertEquals(182.5, (float) $caravan->entry_weight);
        $this->assertSame(1, CaravanWeight::where('caravan_id', $ids[0])->count());
        $this->assertSame(0, CaravanWeight::where('caravan_id', $ids[1])->count());
        $this->assertSame('PURCHASE', CaravanMovement::where('caravan_id', $ids[1])->value('type'));
        $this->assertSame(3, (int) Batch::findOrFail($order['batch']['id'])->caravans_count);

        $line = EntryOrderAnimal::where('caravan_id', $ids[0])->firstOrFail();
        $this->assertSame($this->user->id, (int) $line->received_by_user_id);
        $this->assertNotNull($line->caravan_movement_id);
    }

    public function test_a_dte_is_received_in_parts_and_the_rest_stays_in_transit(): void
    {
        $order = $this->createOrder(['head_count' => 3, 'sex_composition' => 'MALE', 'male_count' => null, 'female_count' => null])->json('order');
        $loaded = $this->loadDte($order['id'], $this->animals('T', 3, 'M', 1));
        $ids = $this->caravanIds($loaded);
        $dteId = $loaded['dtes'][0]['id'];

        $first = $this->receive($order['id'], ['method' => 'MANUAL', 'dte_id' => $dteId, 'received' => $this->lines([$ids[0], $ids[1]])])
            ->assertOk()->json('order');

        $this->assertSame('IN_TRANSIT', $first['status']);
        $this->assertSame(2, $first['received_count']);
        $this->assertSame(1, $first['in_transit_count']);
        $this->assertSame(1, $first['dtes'][0]['in_transit_count']);
        $this->assertSame(2, (int) Batch::findOrFail($order['batch']['id'])->caravans_count);

        $second = $this->receive($order['id'], ['method' => 'CHUTE', 'received' => [['identification' => 'eo-t-3', 'weight' => 190]]])
            ->assertOk()->json('order');

        $this->assertSame('COMPLETED', $second['status']);
        $this->assertSame('CHUTE', $second['dtes'][0]['animals'][2]['reception_method']);
    }

    public function test_the_forty_head_example_follows_documents_then_animals(): void
    {
        $order = $this->createOrder()->json('order');

        $a = $this->loadDte($order['id'], [...$this->animals('A', 15, 'M', 1), ...$this->animals('AH', 10, 'H', 2)]);
        $this->assertSame('AWAITING_DTE', $a['status']);
        $this->assertSame(25, $a['in_transit_count']);

        $afterA = $this->receive($order['id'], ['method' => 'MANUAL', 'dte_id' => $a['dtes'][0]['id'], 'received' => $this->lines($this->caravanIds($a))])
            ->assertOk()->json('order');
        $this->assertSame('AWAITING_DTE', $afterA['status']);
        $this->assertSame(25, $afterA['received_count']);
        $this->assertTrue($afterA['accepts_dte']);

        $b = $this->loadDte($order['id'], [...$this->animals('B', 10, 'M', 1), ...$this->animals('BH', 5, 'H', 2)]);
        $this->assertSame('IN_TRANSIT', $b['status']);
        $this->assertSame(15, $b['in_transit_count']);

        $done = $this->receive($order['id'], ['method' => 'MANUAL', 'dte_id' => $b['dtes'][1]['id'], 'received' => $this->lines($this->caravanIds($b, 1))])
            ->assertOk()->json('order');
        $this->assertSame('COMPLETED', $done['status']);
        $this->assertSame(40, $done['received_count']);
    }

    public function test_the_chute_reads_across_dtes_and_a_manual_reception_finishes(): void
    {
        $order = $this->createOrder(['head_count' => 4, 'sex_composition' => 'MALE', 'male_count' => null, 'female_count' => null])->json('order');
        $this->loadDte($order['id'], $this->animals('C1', 2, 'M', 1));
        $loaded = $this->loadDte($order['id'], $this->animals('C2', 2, 'M', 1));

        $chute = $this->receive($order['id'], ['method' => 'CHUTE', 'received' => [
            ['identification' => 'EO-C1-1', 'weight' => 175],
            ['identification' => 'EO-C2-1', 'weight' => 178],
        ]])->assertOk()->json('order');
        $this->assertSame('IN_TRANSIT', $chute['status']);
        $this->assertSame(2, $chute['received_count']);

        $this->receive($order['id'], ['method' => 'MANUAL', 'dte_id' => $loaded['dtes'][0]['id'], 'received' => $this->lines([$this->caravanIds($loaded)[1]])])
            ->assertOk();
        $done = $this->receive($order['id'], ['method' => 'MANUAL', 'dte_id' => $loaded['dtes'][1]['id'], 'received' => $this->lines([$this->caravanIds($loaded, 1)[1]])])
            ->assertOk()->json('order');

        $this->assertSame('COMPLETED', $done['status']);
    }

    public function test_caravans_that_will_not_arrive_need_a_reason_and_raise_an_incident(): void
    {
        $order = $this->createOrder(['head_count' => 3, 'sex_composition' => 'MALE', 'male_count' => null, 'female_count' => null])->json('order');
        $loaded = $this->loadDte($order['id'], $this->animals('M', 3, 'M', 1), ['dte_number' => 'DTE-MISS']);
        $ids = $this->caravanIds($loaded);
        $dteId = $loaded['dtes'][0]['id'];

        $this->receive($order['id'], ['method' => 'MANUAL', 'dte_id' => $dteId, 'received' => $this->lines([$ids[0]]), 'missing' => [$ids[1], $ids[2]]])
            ->assertStatus(422)
            ->assertJsonPath('header_errors.0.code', 'REASON_REQUIRED');

        $closed = $this->receive($order['id'], [
            'method' => 'MANUAL',
            'dte_id' => $dteId,
            'received' => $this->lines([$ids[0]]),
            'missing' => [$ids[1], $ids[2]],
            'reason' => 'Murieron en el viaje',
        ])->assertOk()->json('order');

        $this->assertSame('CLOSED_INCOMPLETE', $closed['status']);
        $this->assertSame('2 caravanas no llegaron. Motivo: Murieron en el viaje', $closed['closing_reason']);
        $this->assertSame(1, $closed['received_count']);
        $this->assertSame(2, $closed['missing_count']);
        $this->assertSame('MISSING_HEAD', $closed['incidents'][0]['type']);
        $this->assertSame('2 caravanas del DTE DTE-MISS no llegarán: EO-M-2, EO-M-3. Motivo: Murieron en el viaje', $closed['incidents'][0]['detail']);
        $this->assertSame(0, CaravanMovement::where('caravan_id', $ids[1])->count());
        $this->assertSame(1, (int) Batch::findOrFail($order['batch']['id'])->caravans_count);
    }

    public function test_an_order_that_closes_on_a_later_reception_tells_what_did_not_arrive(): void
    {
        $order = $this->createOrder(['head_count' => 2, 'sex_composition' => 'MALE', 'male_count' => null, 'female_count' => null])->json('order');
        $loaded = $this->loadDte($order['id'], $this->animals('W', 2, 'M', 1));
        $ids = $this->caravanIds($loaded);
        $dteId = $loaded['dtes'][0]['id'];

        // One will not arrive, the other comes another day: the order closes on that later reception.
        $this->receive($order['id'], ['method' => 'MANUAL', 'dte_id' => $dteId, 'missing' => [$ids[0]], 'reason' => 'Murió en el viaje'])
            ->assertOk()
            ->assertJsonPath('order.status', 'IN_TRANSIT');

        $closed = $this->receive($order['id'], ['method' => 'MANUAL', 'dte_id' => $dteId, 'received' => $this->lines([$ids[1]])])
            ->assertOk()
            ->json('order');

        $this->assertSame('CLOSED_INCOMPLETE', $closed['status']);
        $this->assertSame('1 caravana no llegó. Motivo: Murió en el viaje', $closed['closing_reason']);
    }

    public function test_each_caravan_that_cannot_be_received_is_reported_by_row(): void
    {
        $order = $this->createOrder(['head_count' => 3, 'sex_composition' => 'MALE', 'male_count' => null, 'female_count' => null])->json('order');
        $loaded = $this->loadDte($order['id'], $this->animals('E', 3, 'M', 1));
        $ids = $this->caravanIds($loaded);
        $dteId = $loaded['dtes'][0]['id'];
        $this->receive($order['id'], ['method' => 'MANUAL', 'dte_id' => $dteId, 'received' => $this->lines([$ids[0]]), 'missing' => [$ids[1]], 'reason' => 'Se perdió'])
            ->assertOk();

        $other = $this->createOrder(['auction_number' => '340'])->json('order');
        $foreign = $this->caravanIds($this->loadDte($other['id'], $this->animals('Z', 1, 'M', 1)))[0];

        $response = $this->receive($order['id'], ['method' => 'MANUAL', 'dte_id' => $dteId, 'received' => $this->lines([$ids[0], $ids[1], $foreign, $ids[2], $ids[2]])])
            ->assertStatus(422);

        $codes = array_column($response->json('row_errors'), 'code', 'row');
        $this->assertSame('CARAVAN_ALREADY_RECEIVED', $codes[0]);
        $this->assertSame('CARAVAN_MARKED_MISSING', $codes[1]);
        $this->assertSame('CARAVAN_NOT_IN_ORDER', $codes[2]);
        $this->assertSame('CARAVAN_DUPLICATED', $codes[4]);
        $this->assertSame(1, EntryOrderAnimal::where('caravan_id', $ids[2])->where('reception_status', 'PENDING')->count());
    }

    public function test_dates_and_empty_receptions_are_refused(): void
    {
        $order = $this->createOrder(['head_count' => 1, 'sex_composition' => 'MALE', 'male_count' => null, 'female_count' => null])->json('order');
        $loaded = $this->loadDte($order['id'], $this->animals('D', 1, 'M', 1));
        $ids = $this->caravanIds($loaded);
        $dteId = $loaded['dtes'][0]['id'];

        $this->receive($order['id'], ['method' => 'MANUAL', 'dte_id' => $dteId, 'received' => $this->lines($ids), 'received_at' => now()->subDays(3)->toDateString()])
            ->assertStatus(422)
            ->assertJsonPath('header_errors.0.code', 'RECEIVED_BEFORE_DTE');

        $this->receive($order['id'], ['method' => 'MANUAL', 'dte_id' => $dteId, 'received' => $this->lines($ids), 'received_at' => now()->addDay()->toDateString()])
            ->assertStatus(422)
            ->assertJsonPath('header_errors.0.code', 'DATE_IN_FUTURE');

        $this->receive($order['id'], ['method' => 'MANUAL', 'dte_id' => $dteId, 'received' => []])
            ->assertStatus(422)
            ->assertJsonPath('header_errors.0.code', 'NOTHING_TO_RECEIVE');

        $this->receive($order['id'], ['method' => 'MANUAL', 'dte_id' => 999999, 'received' => $this->lines($ids)])
            ->assertStatus(422)
            ->assertJsonPath('header_errors.0.code', 'DTE_NOT_IN_ORDER');
    }

    public function test_receive_all_takes_every_dte_at_once(): void
    {
        $order = $this->createOrder(['head_count' => 4, 'sex_composition' => 'MALE', 'male_count' => null, 'female_count' => null])->json('order');
        $this->loadDte($order['id'], $this->animals('RA', 2, 'M', 1), ['dte_number' => 'DTE-RA-1']);
        $loaded = $this->loadDte($order['id'], $this->animals('RB', 2, 'M', 1), ['dte_number' => 'DTE-RB-2']);
        $first = $this->caravanIds($loaded);
        $second = $this->caravanIds($loaded, 1);

        $done = $this->receive($order['id'], [
            'method' => 'MANUAL',
            'received' => $this->lines([$first[0], $first[1], $second[0]]),
            'missing' => [$second[1]],
            'reason' => 'Se escapó en la carga',
        ])->assertOk()->json('order');

        $this->assertSame('CLOSED_INCOMPLETE', $done['status']);
        $this->assertSame(3, $done['received_count']);
        $this->assertSame(1, $done['missing_count']);
        $this->assertSame('1 caravana del DTE DTE-RB-2 no llegará: EO-RB-2. Motivo: Se escapó en la carga', $done['incidents'][0]['detail']);
        $this->assertSame('MANUAL', $done['dtes'][0]['animals'][0]['reception_method']);
    }

    public function test_an_order_with_nothing_in_transit_does_not_receive(): void
    {
        $order = $this->createOrder()->json('order');

        $this->receive($order['id'], ['method' => 'CHUTE', 'received' => [['identification' => 'EO-NONE']]])
            ->assertStatus(422)
            ->assertJsonPath('code', 'ORDER_NOT_RECEIVING');
    }

    public function test_closing_incomplete_from_in_transit_declares_the_pending_ones_missing(): void
    {
        $order = $this->createOrder(['head_count' => 2, 'sex_composition' => 'MALE', 'male_count' => null, 'female_count' => null])->json('order');
        $loaded = $this->loadDte($order['id'], $this->animals('K', 2, 'M', 1));
        $this->receive($order['id'], ['method' => 'MANUAL', 'dte_id' => $loaded['dtes'][0]['id'], 'received' => $this->lines([$this->caravanIds($loaded)[0]])])
            ->assertOk();

        $closed = $this->apiAs('POST', "/entry-orders/{$order['id']}/close-incomplete", ['reason' => 'El camión no vuelve'])
            ->assertOk()->json();

        $this->assertSame('CLOSED_INCOMPLETE', $closed['status']);
        $this->assertSame(1, $closed['received_count']);
        $this->assertSame(1, $closed['missing_count']);
        $this->assertSame(['MISSING_HEAD'], array_column($closed['incidents'], 'type'));
    }
}
