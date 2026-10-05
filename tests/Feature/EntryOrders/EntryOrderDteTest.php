<?php

declare(strict_types=1);

namespace Tests\Feature\EntryOrders;

use App\Models\Batch;
use App\Models\Caravan;
use App\Models\CaravanMovement;
use App\Models\CaravanWeight;
use App\Models\EntryOrderAnimal;
use App\Models\EntryOrderIncident;

/**
 * The caravans of an entry order only exist once a DTE lists them. They inherit what the order
 * declared (category, sex unless both, breed unless several) and its origin, and they are in
 * transit until received: loading the DTE gives no possession.
 */
class EntryOrderDteTest extends EntryOrderTestCase
{
    public function test_loading_a_dte_leaves_its_caravans_in_transit(): void
    {
        $order = $this->createOrder()->json('order');

        $loaded = $this->loadDte($order['id'], [
            ...$this->animals('A', 20, 'M', 1),
            ...$this->animals('B', 10, 'H', 2),
        ], ['dte_number' => '012345678']);

        $this->assertSame('AWAITING_DTE', $loaded['status']);
        $this->assertSame(30, $loaded['with_dte_count']);
        $this->assertSame(10, $loaded['pending_dte_count']);
        $this->assertSame(30, $loaded['in_transit_count']);
        $this->assertSame(0, $loaded['received_count']);
        $this->assertSame(20, $loaded['with_dte_male_count']);
        $this->assertSame(10, $loaded['with_dte_female_count']);
        $this->assertSame('012345678', $loaded['dtes'][0]['dte_number']);
        $this->assertArrayNotHasKey('entered_at', $loaded['dtes'][0]);
        $this->assertCount(30, $loaded['dtes'][0]['animals']);
        $this->assertSame('PENDING', $loaded['dtes'][0]['animals'][0]['reception_status']);

        $caravan = Caravan::where('identification', 'EO-A-1')->firstOrFail();
        $this->assertSame($order['batch']['id'], (int) $caravan->batch_id);
        $this->assertNull($caravan->entry_date);
        $this->assertNull($caravan->entry_weight);
        $this->assertSame($this->categoryId('TERNERO'), (int) $caravan->category_id);
        $this->assertSame($this->breedId('Braford'), (int) $caravan->breed_id);
        $this->assertSame($this->colorId('Colorado'), (int) $caravan->color_id);
        $this->assertSame('012345678', $caravan->provenance_metadata['dte_number']);
        $this->assertSame($order['code'], $caravan->provenance_metadata['extra_data']['entry_order_code']);
        $this->assertSame('338', $caravan->provenance_metadata['auction_name']);

        $this->assertSame(0, CaravanMovement::where('caravan_id', $caravan->id)->count());
        $this->assertSame(0, CaravanWeight::where('caravan_id', $caravan->id)->count());
        $this->assertSame(0, (int) Batch::findOrFail($order['batch']['id'])->caravans_count);
    }

    public function test_the_provenance_of_the_caravans_is_the_order_origin(): void
    {
        $order = $this->createOrder(['farm_id' => $this->otherFarm->id])->json('order');

        $this->loadDte($order['id'], $this->animals('O', 1));

        $caravan = Caravan::where('identification', 'EO-O-1')->firstOrFail();
        $this->assertSame($this->provider->id, (int) $caravan->provider_id);
        $this->assertSame('01.234.5.67890/01', $caravan->renspa);
        $this->assertSame('01.234.5.67890/01', $caravan->provenance_metadata['origin_renspa']);
        $this->assertSame($this->provider->id, $caravan->provenance_metadata['origin_provider_id']);
    }

    public function test_the_order_moves_to_in_transit_once_every_head_has_its_dte(): void
    {
        $order = $this->createOrder()->json('order');
        $this->loadDte($order['id'], [...$this->animals('A', 20, 'M', 1), ...$this->animals('B', 10, 'H', 2)]);

        $loaded = $this->loadDte($order['id'], [...$this->animals('C', 5, 'M', 1), ...$this->animals('D', 5, 'H', 2)]);

        $this->assertSame('IN_TRANSIT', $loaded['status']);
        $this->assertSame(0, $loaded['pending_dte_count']);
        $this->assertSame(40, $loaded['in_transit_count']);
        $this->assertCount(2, $loaded['dtes']);
        $this->assertNull($loaded['closed_at']);
        $this->assertFalse($loaded['accepts_dte']);
        $this->assertTrue($loaded['accepts_reception']);

        $this->apiAs('POST', "/entry-orders/{$order['id']}/dtes", $this->dte($this->animals('E', 1)))
            ->assertStatus(422)
            ->assertJsonPath('code', 'ENTRY_ORDER_NOT_ACCEPTING_DTE');
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

        $this->apiAs('POST', "/entry-orders/{$order['id']}/dtes", $this->dte($this->animals('S', 3, null, null, null)))
            ->assertCreated()
            ->assertJsonPath('order.status', 'IN_TRANSIT')
            ->assertJsonPath('warnings', []);

        $caravan = Caravan::where('identification', 'EO-S-1')->firstOrFail();
        $this->assertSame('M', $caravan->sex->value);
        $this->assertSame($this->breedId('Angus'), (int) $caravan->breed_id);
    }

    public function test_every_problem_of_the_dte_is_reported_by_row_and_nothing_is_written(): void
    {
        $order = $this->createOrder()->json('order');
        Caravan::create(['company_id' => $this->company->id, 'identification' => 'EO-EXISTS', 'sex' => 'M', 'category_id' => $this->categoryId('TERNERO')]);

        $response = $this->apiAs('POST', "/entry-orders/{$order['id']}/dtes", $this->dte([
            ['caravana' => 'EO-EXISTS', 'sex' => 'M', 'breed_position' => 1],
            ['caravana' => 'EO-DUP', 'sex' => 'M', 'breed_position' => 1],
            ['caravana' => 'EO-DUP', 'sex' => 'H', 'breed_position' => 1],
            ['caravana' => 'EO-NOSEX', 'sex' => null, 'breed_position' => 1],
            ['caravana' => 'EO-BREED', 'sex' => 'H', 'breed_position' => 3],
        ]))->assertStatus(422);

        $codes = array_column($response->json('row_errors'), 'code', 'row');
        $this->assertSame('CARAVAN_EXISTS', $codes[0]);
        $this->assertSame('CARAVAN_DUPLICATED', $codes[2]);
        $this->assertSame('SEX_MISSING', $codes[3]);
        $this->assertSame('BREED_UNKNOWN', $codes[4]);

        $this->assertSame(0, Caravan::where('identification', 'like', 'EO-DUP%')->count());
        $this->assertSame('AWAITING_DTE', $this->apiAs('GET', "/entry-orders/{$order['id']}")->json('status'));
    }

    public function test_more_head_than_bought_is_loaded_and_raises_an_incident(): void
    {
        $order = $this->createOrder([
            'head_count' => 2,
            'sex_composition' => 'MALE',
            'male_count' => null,
            'female_count' => null,
        ])->json('order');

        $response = $this->apiAs('POST', "/entry-orders/{$order['id']}/dtes", $this->dte($this->animals('X', 4, 'M', 1), ['dte_number' => 'DTE-EXC']))
            ->assertCreated();

        $loaded = $response->json('order');
        $this->assertSame('IN_TRANSIT', $loaded['status']);
        $this->assertSame(2, $loaded['head_count']);
        $this->assertSame(4, $loaded['with_dte_count']);
        $this->assertSame(4, Caravan::where('identification', 'like', 'EO-X-%')->count());
        $this->assertSame(1, $loaded['open_incidents_count']);
        $this->assertSame('EXCESS_HEAD', $loaded['incidents'][0]['type']);
        $this->assertSame('DTE-EXC', $loaded['incidents'][0]['dte_number']);
        $this->assertStringContainsString('(+2)', $loaded['incidents'][0]['detail']);
        $this->assertContains('EXCESS_HEAD', array_column($response->json('warnings'), 'code'));
    }

    public function test_more_of_a_sex_than_declared_raises_its_own_incident(): void
    {
        $order = $this->createOrder(['head_count' => 4, 'male_count' => 2, 'female_count' => 2])->json('order');

        $loaded = $this->loadDte($order['id'], [...$this->animals('M', 3, 'M', 1), ...$this->animals('F', 1, 'H', 1)]);

        $this->assertSame('IN_TRANSIT', $loaded['status']);
        $this->assertSame(['EXCESS_MALES'], array_column($loaded['incidents'], 'type'));
        $this->assertSame(1, EntryOrderIncident::where('entry_order_id', $order['id'])->count());
    }

    public function test_the_same_dte_cannot_be_loaded_twice(): void
    {
        $first = $this->createOrder()->json('order');
        $second = $this->createOrder(['auction_number' => '339'])->json('order');

        $this->loadDte($first['id'], $this->animals('F', 1), ['dte_number' => 'dte-777']);

        $response = $this->apiAs('POST', "/entry-orders/{$second['id']}/dtes", $this->dte($this->animals('G', 1), ['dte_number' => 'DTE-777']))
            ->assertStatus(422);

        $this->assertSame('DTE_ALREADY_LOADED', $response->json('header_errors.0.code'));
        $this->assertStringContainsString($first['code'], $response->json('header_errors.0.message'));
    }

    public function test_the_dte_date_cannot_be_future_or_before_the_purchase(): void
    {
        $order = $this->createOrder()->json('order');

        $this->apiAs('POST', "/entry-orders/{$order['id']}/dtes", $this->dte($this->animals('T', 1), ['dte_date' => now()->addDay()->toDateString()]))
            ->assertStatus(422);

        $response = $this->apiAs('POST', "/entry-orders/{$order['id']}/dtes", $this->dte($this->animals('T', 1), [
            'dte_date' => now()->subDays(5)->toDateString(),
        ]))->assertStatus(422);

        $this->assertSame('DTE_BEFORE_PURCHASE', $response->json('header_errors.0.code'));
    }

    public function test_blank_breeds_are_warned_not_blocked(): void
    {
        $order = $this->createOrder()->json('order');

        $response = $this->apiAs('POST', "/entry-orders/{$order['id']}/dtes", $this->dte([
            ['caravana' => 'EO-NOBREED', 'sex' => 'H', 'breed_position' => null],
        ]))->assertCreated();

        $this->assertSame(['BREED_UNDECLARED'], array_column($response->json('warnings'), 'code'));
        $this->assertNull(Caravan::where('identification', 'EO-NOBREED')->value('breed_id'));
    }

    public function test_an_order_with_a_dte_is_not_cancelled_but_closed_incomplete(): void
    {
        $order = $this->createOrder(['head_count' => 3, 'male_count' => 2, 'female_count' => 1])->json('order');
        $loaded = $this->loadDte($order['id'], $this->animals('L', 2, 'M', 1));
        $this->assertFalse($loaded['can_cancel']);
        $this->assertTrue($loaded['can_close_incomplete']);

        $this->apiAs('POST', "/entry-orders/{$order['id']}/cancel", ['reason' => 'No vino'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'INVALID_STATE_TRANSITION');

        $closed = $this->apiAs('POST', "/entry-orders/{$order['id']}/close-incomplete", ['reason' => 'El vendedor no manda la ternera'])
            ->assertOk()
            ->json();

        // The two caravans of the DTE were still in transit: they will not arrive either. The heifer
        // that never got a DTE is also left to settle with the seller.
        $this->assertSame('CLOSED_INCOMPLETE', $closed['status']);
        $this->assertSame(2, $closed['missing_count']);
        $this->assertSame(['MISSING_HEAD', 'MISSING_DTE'], array_column($closed['incidents'], 'type'));
        $this->assertStringContainsString('1 cabeza nunca tuvo DTE', $closed['incidents'][1]['detail']);
        $this->assertSame(2, EntryOrderAnimal::where('entry_order_id', $order['id'])->where('reception_status', 'MISSING')->count());

        $this->apiAs('POST', "/entry-orders/{$order['id']}/dtes", $this->dte($this->animals('M', 1, 'H', 1)))
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

    public function test_the_dte_survives_the_reception_and_the_assignment_to_an_own_batch(): void
    {
        $order = $this->createOrder()->json('order');
        $loaded = $this->loadDte($order['id'], $this->animals('P', 1), ['dte_number' => 'DTE-ASSIGN']);
        $this->receive($order['id'], ['method' => 'MANUAL', 'dte_id' => $loaded['dtes'][0]['id'], 'received' => $this->lines($this->caravanIds($loaded))])
            ->assertOk();

        $own = Batch::create(['company_id' => $this->company->id, 'name' => 'Recría Propia EO', 'is_active' => true, 'is_confined' => false]);
        $caravan = Caravan::where('identification', 'EO-P-1')->firstOrFail();

        $this->apiAs('POST', '/batches/assign-to-own', [
            'caravan_ids' => [$caravan->id],
            'target_batch_id' => $own->id,
            'entry_date' => now()->toDateString(),
        ])->assertOk();

        $this->assertSame('DTE-ASSIGN', $caravan->fresh()->provenance_metadata['dte_number']);
    }
}
