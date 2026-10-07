<?php

declare(strict_types=1);

namespace Tests\Feature\EntryOrders;

use App\Models\Batch;
use App\Models\Caravan;
use App\Models\CaravanMovement;
use App\Models\CaravanWeight;
use App\Models\EntryOrderAnimal;

/**
 * Receiving the animals of a DTE: each line is a caravan written down, and receiving it creates it
 * with what the order declared. Head left out stay in transit unless declared missing; animals of
 * more are received and raised as an incident.
 */
class EntryOrderReceptionTest extends EntryOrderTestCase
{
    public function test_a_reception_creates_the_caravans_and_completes_the_order(): void
    {
        $order = $this->createOrder(['head_count' => 3, 'male_count' => 2, 'female_count' => 1])->json('order');
        $loaded = $this->loadDte($order['id'], 3, ['dte_number' => '012345678']);

        $received = $this->receiveOn($loaded, [
            ['caravana' => 'EO-R-1', 'sex' => 'M', 'breed_position' => 1, 'weight' => 182.5, 'body_condition' => 3.5],
            ['caravana' => 'EO-R-2', 'sex' => 'M', 'breed_position' => 1],
            ['caravana' => 'EO-H-1', 'sex' => 'H', 'breed_position' => 2],
        ]);

        $this->assertSame('COMPLETED', $received['status']);
        $this->assertSame(3, $received['received_count']);
        $this->assertSame(0, $received['in_transit_count']);
        $this->assertSame(2, $received['received_male_count']);
        $this->assertSame(1, $received['received_female_count']);
        $this->assertNotNull($received['closed_at']);
        $this->assertSame('MANUAL', $received['dtes'][0]['animals'][0]['reception_method']);
        $this->assertSame(now()->toDateString(), $received['dtes'][0]['animals'][0]['received_at']);

        $caravan = Caravan::where('identification', 'EO-R-1')->firstOrFail();
        $this->assertSame($order['batch']['id'], (int) $caravan->batch_id);
        $this->assertSame(now()->toDateString(), $caravan->entry_date->toDateString());
        $this->assertEquals(182.5, (float) $caravan->entry_weight);
        $this->assertSame('M', $caravan->sex->value);
        $this->assertSame($this->categoryId('TERNERO'), (int) $caravan->category_id);
        $this->assertSame($this->breedId('Braford'), (int) $caravan->breed_id);
        $this->assertSame($this->colorId('Colorado'), (int) $caravan->color_id);
        $this->assertSame('012345678', $caravan->provenance_metadata['dte_number']);
        $this->assertSame($order['code'], $caravan->provenance_metadata['extra_data']['entry_order_code']);
        $this->assertSame('338', $caravan->provenance_metadata['auction_name']);
        $this->assertSame(1, CaravanWeight::where('caravan_id', $caravan->id)->count());
        $this->assertSame('PURCHASE', CaravanMovement::where('caravan_id', $caravan->id)->value('type'));

        $heifer = Caravan::where('identification', 'EO-H-1')->firstOrFail();
        $this->assertSame('H', $heifer->sex->value);
        $this->assertSame($this->breedId('Brangus'), (int) $heifer->breed_id);
        $this->assertSame(0, CaravanWeight::where('caravan_id', $heifer->id)->count());

        $this->assertSame(3, (int) Batch::findOrFail($order['batch']['id'])->caravans_count);
        $line = EntryOrderAnimal::where('caravan_id', $caravan->id)->firstOrFail();
        $this->assertSame($this->user->id, (int) $line->received_by_user_id);
        $this->assertSame($loaded['dtes'][0]['id'], (int) $line->entry_order_dte_id);
        $this->assertNotNull($line->caravan_movement_id);
    }

    public function test_the_provenance_of_the_caravans_is_the_order_origin(): void
    {
        $order = $this->createOrder(['farm_id' => $this->otherFarm->id, 'head_count' => 1, 'sex_composition' => 'MALE', 'male_count' => null, 'female_count' => null])->json('order');
        $this->receiveOn($this->loadDte($order['id'], 1), $this->animals('O', 1));

        $caravan = Caravan::where('identification', 'EO-O-1')->firstOrFail();
        $this->assertSame($this->provider->id, (int) $caravan->provider_id);
        $this->assertSame('01.234.5.67890/01', $caravan->renspa);
        $this->assertSame('01.234.5.67890/01', $caravan->provenance_metadata['origin_renspa']);
        $this->assertSame($this->provider->id, $caravan->provenance_metadata['origin_provider_id']);
    }

    public function test_a_single_sex_single_breed_order_asks_nothing_per_caravan(): void
    {
        $order = $this->createOrder([
            'sex_composition' => 'MALE',
            'male_count' => null,
            'female_count' => null,
            'head_count' => 3,
            'breeds' => [['breed_id' => $this->breedId('Angus'), 'color_id' => $this->colorId('Negro')]],
        ])->json('order');
        $loaded = $this->loadDte($order['id'], 3);

        $this->receive($order['id'], ['dte_id' => $loaded['dtes'][0]['id'], 'animals' => $this->animals('S', 3, null, null, null)])
            ->assertOk()
            ->assertJsonPath('order.status', 'COMPLETED')
            ->assertJsonPath('warnings', []);

        $caravan = Caravan::where('identification', 'EO-S-1')->firstOrFail();
        $this->assertSame('M', $caravan->sex->value);
        $this->assertSame($this->breedId('Angus'), (int) $caravan->breed_id);
    }

    public function test_a_dte_is_received_in_parts_and_the_rest_stays_in_transit(): void
    {
        $order = $this->createOrder(['head_count' => 3, 'sex_composition' => 'MALE', 'male_count' => null, 'female_count' => null])->json('order');
        $loaded = $this->loadDte($order['id'], 3);

        $first = $this->receiveOn($loaded, $this->animals('T', 2));

        $this->assertSame('IN_TRANSIT', $first['status']);
        $this->assertSame(2, $first['received_count']);
        $this->assertSame(1, $first['in_transit_count']);
        $this->assertSame(1, $first['dtes'][0]['pending_count']);
        $this->assertSame(2, (int) Batch::findOrFail($order['batch']['id'])->caravans_count);

        $second = $this->receiveOn($first, [['caravana' => 'EO-T-3', 'weight' => 190]]);

        $this->assertSame('COMPLETED', $second['status']);
        $this->assertCount(3, $second['dtes'][0]['animals']);
    }

    public function test_the_forty_head_example_follows_documents_then_animals(): void
    {
        $order = $this->createOrder()->json('order');

        $a = $this->loadDte($order['id'], 25);
        $this->assertSame('AWAITING_DTE', $a['status']);
        $this->assertSame(25, $a['in_transit_count']);

        $afterA = $this->receiveOn($a, [...$this->animals('A', 15, 'M', 1), ...$this->animals('AH', 10, 'H', 2)]);
        $this->assertSame('AWAITING_DTE', $afterA['status']);
        $this->assertSame(25, $afterA['received_count']);
        $this->assertTrue($afterA['accepts_dte']);

        $b = $this->loadDte($order['id'], 15);
        $this->assertSame('IN_TRANSIT', $b['status']);
        $this->assertSame(15, $b['in_transit_count']);

        $done = $this->receiveOn($b, [...$this->animals('B', 10, 'M', 1), ...$this->animals('BH', 5, 'H', 2)], 1);
        $this->assertSame('COMPLETED', $done['status']);
        $this->assertSame(40, $done['received_count']);
    }

    public function test_head_that_will_not_arrive_need_a_reason_and_raise_an_incident(): void
    {
        $order = $this->createOrder(['head_count' => 3, 'sex_composition' => 'MALE', 'male_count' => null, 'female_count' => null])->json('order');
        $loaded = $this->loadDte($order['id'], 3, ['dte_number' => 'DTE-MISS']);
        $dteId = $loaded['dtes'][0]['id'];

        $this->receive($order['id'], ['dte_id' => $dteId, 'animals' => $this->animals('M', 1), 'missing_head_count' => 2])
            ->assertStatus(422)
            ->assertJsonPath('header_errors.0.code', 'REASON_REQUIRED');

        $this->receive($order['id'], ['dte_id' => $dteId, 'animals' => $this->animals('M', 1), 'missing_head_count' => 3, 'reason' => 'Murieron'])
            ->assertStatus(422)
            ->assertJsonPath('header_errors.0.code', 'MISSING_EXCEEDS_PENDING');

        $closed = $this->receiveOn($loaded, $this->animals('M', 1), 0, ['missing_head_count' => 2, 'reason' => 'Murieron en el viaje']);

        $this->assertSame('CLOSED_INCOMPLETE', $closed['status']);
        $this->assertSame('2 cabezas no llegaron. Motivo: Murieron en el viaje', $closed['closing_reason']);
        $this->assertSame(1, $closed['received_count']);
        $this->assertSame(2, $closed['missing_count']);
        $this->assertSame(2, $closed['dtes'][0]['missing_head_count']);
        $this->assertSame('MISSING_HEAD', $closed['incidents'][0]['type']);
        $this->assertSame('2 cabezas del DTE DTE-MISS no llegarán. Motivo: Murieron en el viaje', $closed['incidents'][0]['detail']);
        $this->assertSame(1, (int) Batch::findOrFail($order['batch']['id'])->caravans_count);
    }

    public function test_an_order_that_closes_on_a_later_reception_tells_what_did_not_arrive(): void
    {
        $order = $this->createOrder(['head_count' => 2, 'sex_composition' => 'MALE', 'male_count' => null, 'female_count' => null])->json('order');
        $loaded = $this->loadDte($order['id'], 2);

        // One will not arrive, the other comes another day: the order closes on that later reception.
        $first = $this->receiveOn($loaded, [], 0, ['missing_head_count' => 1, 'reason' => 'Murió en el viaje']);
        $this->assertSame('IN_TRANSIT', $first['status']);

        $closed = $this->receiveOn($first, $this->animals('W', 1));

        $this->assertSame('CLOSED_INCOMPLETE', $closed['status']);
        $this->assertSame('1 cabeza no llegó. Motivo: Murió en el viaje', $closed['closing_reason']);
    }

    public function test_animals_of_more_are_received_and_raise_an_incident(): void
    {
        $order = $this->createOrder(['head_count' => 2, 'sex_composition' => 'MALE', 'male_count' => null, 'female_count' => null])->json('order');
        $loaded = $this->loadDte($order['id'], 2, ['dte_number' => 'DTE-MORE']);

        $response = $this->receive($order['id'], ['dte_id' => $loaded['dtes'][0]['id'], 'animals' => $this->animals('X', 3)])->assertOk();
        $received = $response->json('order');

        $this->assertSame('COMPLETED', $received['status']);
        $this->assertSame(3, $received['received_count']);
        $this->assertSame(1, $received['dtes'][0]['excess_count']);
        $this->assertSame(3, (int) Batch::findOrFail($order['batch']['id'])->caravans_count);
        $this->assertSame(['ARRIVAL_EXCESS'], array_column($received['incidents'], 'type'));
        $this->assertSame('El DTE DTE-MORE declara 2 cabezas; llegaron 3 (+1): EO-X-3.', $received['incidents'][0]['detail']);
        $this->assertContains('ARRIVAL_EXCESS', array_column($response->json('warnings'), 'code'));
    }

    public function test_more_of_a_sex_than_declared_raises_its_own_incident(): void
    {
        $order = $this->createOrder(['head_count' => 4, 'male_count' => 2, 'female_count' => 2])->json('order');
        $loaded = $this->loadDte($order['id'], 4);

        $received = $this->receiveOn($loaded, [...$this->animals('M', 3, 'M', 1), ...$this->animals('F', 1, 'H', 1)]);

        $this->assertSame('COMPLETED', $received['status']);
        $this->assertSame(['EXCESS_MALES'], array_column($received['incidents'], 'type'));
    }

    public function test_every_problem_of_the_reception_is_reported_by_row_and_nothing_is_written(): void
    {
        $order = $this->createOrder()->json('order');
        $loaded = $this->loadDte($order['id'], 10);
        Caravan::create(['company_id' => $this->company->id, 'identification' => 'EO-EXISTS', 'sex' => 'M', 'category_id' => $this->categoryId('TERNERO')]);

        $response = $this->receive($order['id'], ['dte_id' => $loaded['dtes'][0]['id'], 'animals' => [
            ['caravana' => 'EO-EXISTS', 'sex' => 'M', 'breed_position' => 1],
            ['caravana' => 'EO-DUP', 'sex' => 'M', 'breed_position' => 1],
            ['caravana' => 'EO-DUP', 'sex' => 'H', 'breed_position' => 1],
            ['caravana' => 'EO-NOSEX', 'sex' => null, 'breed_position' => 1],
            ['caravana' => 'EO-BREED', 'sex' => 'H', 'breed_position' => 3],
            ['caravana' => 'EO-EC', 'sex' => 'H', 'breed_position' => 1, 'body_condition' => 6],
            ['caravana' => '', 'sex' => 'H', 'breed_position' => 1],
        ]])->assertStatus(422);

        $codes = array_column($response->json('row_errors'), 'code', 'row');
        $this->assertSame('CARAVAN_EXISTS', $codes[0]);
        $this->assertSame('CARAVAN_DUPLICATED', $codes[2]);
        $this->assertSame('SEX_MISSING', $codes[3]);
        $this->assertSame('BREED_UNKNOWN', $codes[4]);
        $this->assertSame('BODY_CONDITION_INVALID', $codes[5]);
        $this->assertSame('CARAVAN_MISSING', $codes[6]);
        $this->assertSame(0, Caravan::where('identification', 'like', 'EO-DUP%')->count());
        $this->assertSame(0, $this->apiAs('GET', "/entry-orders/{$order['id']}")->json('received_count'));
    }

    public function test_a_sex_that_contradicts_the_order_is_refused(): void
    {
        $order = $this->createOrder(['head_count' => 1, 'sex_composition' => 'MALE', 'male_count' => null, 'female_count' => null])->json('order');
        $loaded = $this->loadDte($order['id'], 1);

        $this->receive($order['id'], ['dte_id' => $loaded['dtes'][0]['id'], 'animals' => $this->animals('C', 1, 'H')])
            ->assertStatus(422)
            ->assertJsonPath('row_errors.0.code', 'SEX_CONTRADICTS_ORDER');
    }

    public function test_blank_breeds_are_warned_not_blocked(): void
    {
        $order = $this->createOrder()->json('order');
        $loaded = $this->loadDte($order['id'], 40);

        $response = $this->receive($order['id'], ['dte_id' => $loaded['dtes'][0]['id'], 'animals' => [
            ['caravana' => 'EO-NOBREED', 'sex' => 'H', 'breed_position' => null],
        ]])->assertOk();

        $this->assertSame(['BREED_UNDECLARED'], array_column($response->json('warnings'), 'code'));
        $this->assertNull(Caravan::where('identification', 'EO-NOBREED')->value('breed_id'));
    }

    public function test_dates_empty_receptions_and_foreign_dtes_are_refused(): void
    {
        $order = $this->createOrder(['head_count' => 1, 'sex_composition' => 'MALE', 'male_count' => null, 'female_count' => null])->json('order');
        $loaded = $this->loadDte($order['id'], 1);
        $dteId = $loaded['dtes'][0]['id'];
        $animals = $this->animals('D', 1);

        $this->receive($order['id'], ['dte_id' => $dteId, 'animals' => $animals, 'received_at' => now()->subDays(3)->toDateString()])
            ->assertStatus(422)
            ->assertJsonPath('header_errors.0.code', 'RECEIVED_BEFORE_DTE');

        $this->receive($order['id'], ['dte_id' => $dteId, 'animals' => $animals, 'received_at' => now()->addDay()->toDateString()])
            ->assertStatus(422)
            ->assertJsonPath('header_errors.0.code', 'DATE_IN_FUTURE');

        $this->receive($order['id'], ['dte_id' => $dteId, 'animals' => []])
            ->assertStatus(422)
            ->assertJsonPath('header_errors.0.code', 'NOTHING_TO_RECEIVE');

        $this->receive($order['id'], ['dte_id' => 999999, 'animals' => $animals])
            ->assertStatus(422)
            ->assertJsonPath('header_errors.0.code', 'DTE_NOT_IN_ORDER');

        $this->receive($order['id'], ['method' => 'CHUTE', 'dte_id' => $dteId, 'animals' => $animals])
            ->assertStatus(422)
            ->assertJsonValidationErrors('method');
    }

    public function test_a_dte_with_nothing_in_transit_does_not_receive(): void
    {
        $order = $this->createOrder(['head_count' => 2, 'sex_composition' => 'MALE', 'male_count' => null, 'female_count' => null])->json('order');
        $this->loadDte($order['id'], 1);
        $loaded = $this->loadDte($order['id'], 1);
        $this->receiveOn($loaded, $this->animals('N', 1));

        $this->receive($order['id'], ['dte_id' => $loaded['dtes'][0]['id'], 'animals' => $this->animals('N2', 1)])
            ->assertStatus(422)
            ->assertJsonPath('header_errors.0.code', 'DTE_NOTHING_PENDING');
    }

    public function test_an_order_without_a_dte_does_not_receive(): void
    {
        $order = $this->createOrder(['auction_number' => '901'])->json('order');

        $this->receive($order['id'], ['dte_id' => 1, 'animals' => $this->animals('NONE', 1)])
            ->assertStatus(422)
            ->assertJsonPath('header_errors.0.code', 'DTE_NOT_IN_ORDER');
    }

    public function test_closing_incomplete_from_in_transit_declares_the_pending_head_missing(): void
    {
        $order = $this->createOrder(['head_count' => 2, 'sex_composition' => 'MALE', 'male_count' => null, 'female_count' => null])->json('order');
        $this->receiveOn($this->loadDte($order['id'], 2), $this->animals('K', 1));

        $closed = $this->apiAs('POST', "/entry-orders/{$order['id']}/close-incomplete", ['reason' => 'El camión no vuelve'])
            ->assertOk()->json();

        $this->assertSame('CLOSED_INCOMPLETE', $closed['status']);
        $this->assertSame(1, $closed['received_count']);
        $this->assertSame(1, $closed['missing_count']);
        $this->assertSame(['MISSING_HEAD'], array_column($closed['incidents'], 'type'));
    }
}
