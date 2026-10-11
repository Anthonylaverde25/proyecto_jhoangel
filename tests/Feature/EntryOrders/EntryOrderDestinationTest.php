<?php

declare(strict_types=1);

namespace Tests\Feature\EntryOrders;

use App\Models\Batch;
use App\Models\Caravan;
use App\Models\CaravanMovement;

/**
 * Where the animals of a purchase go, declared on the order by category line and sex: they enter
 * the own batches directly when they are received, and only what has no destination goes to a
 * transit batch, created when the purchase is confirmed.
 */
class EntryOrderDestinationTest extends EntryOrderTestCase
{
    /**
     * 40 calves (both sexes) and 20 heifers: 25 males, 35 females.
     *
     * @param list<array<string, mixed>> $destinations
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function mixedTroop(array $destinations, array $overrides = []): array
    {
        return $this->troop([
            'categories' => [
                ['category_id' => $this->categoryId('TERNERO'), 'head_count' => 40],
                ['category_id' => $this->categoryId('VAQUILLONA'), 'head_count' => 20],
            ],
            'male_count' => 25,
            'female_count' => 35,
            'destinations' => $destinations,
            ...$overrides,
        ]);
    }

    /**
     * @param array<string, mixed> $troop
     * @return \Illuminate\Testing\TestResponse
     */
    private function store(array $troop, bool $confirm = true)
    {
        return $this->apiAs('POST', '/entry-orders', [...$troop, 'confirm' => $confirm]);
    }

    public function test_a_troop_all_to_one_own_batch_has_no_transit_batch(): void
    {
        $own = $this->ownBatch('Recría Macho 2026');
        $batchesBefore = Batch::count();

        // No auction and an automatic name: there is no transit batch to name.
        $order = $this->store($this->mixedTroop(
            [$this->destination(1, $own->id), $this->destination(2, $own->id)],
            ['auction_number' => null]
        ))->assertCreated()->json('order');

        $this->assertSame('AWAITING_DTE', $order['status']);
        $this->assertNull($order['batch']);
        $this->assertFalse($order['needs_transit_batch']);
        $this->assertSame([], $order['uncovered']);
        $this->assertSame([['id' => $own->id, 'name' => 'Recría Macho 2026', 'is_transit' => false, 'rules' => ['Ternero', 'Vaquillona']]], $order['possible_batches']);
        $this->assertSame($batchesBefore, Batch::count());
    }

    public function test_calves_split_by_sex_enter_their_own_batches_directly(): void
    {
        $males = $this->ownBatch('Recría Macho 2026');
        $females = $this->ownBatch('Reposición Vaquillonas');
        $batchesBefore = Batch::count();

        $order = $this->store($this->mixedTroop([
            $this->destination(1, $males->id, 'M'),
            $this->destination(1, $females->id, 'H'),
            $this->destination(2, $females->id),
        ]))->assertCreated()->json('order');

        $this->assertNull($order['batch']);
        $this->assertSame($batchesBefore, Batch::count(), 'No transit batch is created.');
        $this->assertSame(['Ternero · machos', 'Ternero · hembras', 'Vaquillona'], array_column($order['destinations'], 'label'));

        $loaded = $this->loadDte($order['id'], 4);
        $received = $this->receiveOn($loaded, [
            ['caravana' => 'EO-DS-1', 'sex' => 'M', 'breed_position' => 1, 'weight' => 180],
            ['caravana' => 'EO-DS-2', 'sex' => 'H', 'breed_position' => 1, 'category_position' => 1, 'weight' => 170],
            ['caravana' => 'EO-DS-3', 'sex' => 'H', 'breed_position' => 2, 'category_position' => 2, 'weight' => 210],
            ['caravana' => 'EO-DS-4', 'sex' => 'M', 'breed_position' => 2, 'weight' => 190],
        ]);

        $this->assertSame(4, $received['received_count']);
        $this->assertSame(['EO-DS-1', 'EO-DS-4'], Caravan::where('batch_id', $males->id)->orderBy('identification')->pluck('identification')->all());
        $this->assertSame(['EO-DS-2', 'EO-DS-3'], Caravan::where('batch_id', $females->id)->orderBy('identification')->pluck('identification')->all());

        // The own farm's RENSPA operates the animal; the seller's stays in its provenance.
        $caravan = Caravan::where('identification', 'EO-DS-1')->firstOrFail();
        $this->assertSame('02.111.0.00001/00', $caravan->renspa);
        $this->assertSame($this->provider->id, (int) $caravan->provider_id);
        $this->assertSame('01.234.5.67890/00', $caravan->provenance_metadata['origin_renspa']);
        $this->assertSame(now()->toDateString(), $caravan->provenance_metadata['assigned_to_own_batch_at']);

        // A single purchase movement, straight into the own batch.
        $movements = CaravanMovement::where('caravan_id', $caravan->id)->get();
        $this->assertCount(1, $movements);
        $this->assertSame('PURCHASE', $movements[0]->type);
        $this->assertSame($males->id, (int) $movements[0]->to_batch_id);
        $this->assertNull($movements[0]->from_batch_id);
        $this->assertSame('01.234.5.67890/00', $movements[0]->from_renspa);

        $this->assertSame(2, (int) $males->fresh()->caravans_count);
        $this->assertSame(2, (int) $females->fresh()->caravans_count);

        // The order tells where each animal is today.
        $detail = $this->apiAs('GET', "/entry-orders/{$order['id']}")->json();
        $current = array_column(array_column($detail['dtes'][0]['animals'], 'current_batch'), 'name');
        $this->assertSame(['Recría Macho 2026', 'Reposición Vaquillonas', 'Reposición Vaquillonas', 'Recría Macho 2026'], $current);
    }

    public function test_what_has_no_destination_goes_to_a_transit_batch(): void
    {
        $males = $this->ownBatch('Recría Macho 2026');

        // Only the male calves have a destination: female calves and heifers need the transit batch, and its name.
        $this->store($this->mixedTroop([$this->destination(1, $males->id, 'M')], ['batch_name_mode' => 'CUSTOM', 'batch_name' => null]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'BATCH_NAME_MISSING');

        $order = $this->store($this->mixedTroop([$this->destination(1, $males->id, 'M')]))->assertCreated()->json('order');

        $this->assertNotNull($order['batch']);
        $this->assertTrue($order['needs_transit_batch']);
        $this->assertSame([['category_position' => 1, 'sex' => 'H'], ['category_position' => 2, 'sex' => 'H']], $order['uncovered']);

        $loaded = $this->loadDte($order['id'], 2);
        $this->receiveOn($loaded, [
            ['caravana' => 'EO-TR-1', 'sex' => 'M', 'breed_position' => 1],
            ['caravana' => 'EO-TR-2', 'sex' => 'H', 'breed_position' => 1, 'category_position' => 1],
        ]);

        $this->assertSame($males->id, (int) Caravan::where('identification', 'EO-TR-1')->value('batch_id'));
        $this->assertSame($order['batch']['id'], (int) Caravan::where('identification', 'EO-TR-2')->value('batch_id'));
        // In the transit batch the animal keeps the seller's RENSPA until it is assigned.
        $this->assertSame('01.234.5.67890/00', Caravan::where('identification', 'EO-TR-2')->value('renspa'));
    }

    public function test_a_line_is_split_by_sex_only_when_it_can_be(): void
    {
        $batch = $this->ownBatch('Reposición');

        $this->store($this->mixedTroop([$this->destination(2, $batch->id, 'H')]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'DESTINATION_SEX_NOT_SPLITTABLE');

        $this->store($this->mixedTroop([$this->destination(1, $batch->id), $this->destination(1, $batch->id, 'M')]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'DESTINATION_SCOPE_CONFLICT');

        // A troop of one sex: its calves cannot be split either.
        $this->store($this->troop(['sex_composition' => 'MALE', 'male_count' => null, 'female_count' => null, 'destinations' => [$this->destination(1, $batch->id, 'M')]]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'DESTINATION_SEX_NOT_SPLITTABLE');
    }

    public function test_a_destination_is_an_own_active_batch(): void
    {
        $external = Batch::create(['company_id' => $this->company->id, 'name' => 'Lote del vendedor', 'farm_id' => $this->farm->id, 'is_active' => true]);
        $closed = $this->ownBatch('Lote cerrado', false);

        $this->store($this->mixedTroop([$this->destination(1, $external->id)]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'DESTINATION_NOT_OWN');

        $this->store($this->mixedTroop([$this->destination(1, $closed->id)]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'DESTINATION_INACTIVE');
    }

    public function test_a_destination_closed_after_the_purchase_stops_the_reception(): void
    {
        $own = $this->ownBatch('Recría 2026');
        $order = $this->store($this->mixedTroop([$this->destination(1, $own->id), $this->destination(2, $own->id)]))->json('order');
        $loaded = $this->loadDte($order['id'], 1);

        $own->update(['is_active' => false]);

        $this->receive($order['id'], ['dte_id' => $loaded['dtes'][0]['id'], 'animals' => [['caravana' => 'EO-CL-1', 'sex' => 'M', 'breed_position' => 1]]])
            ->assertStatus(422)
            ->assertJsonPath('code', 'DESTINATION_UNAVAILABLE');

        $this->assertSame(0, Caravan::where('identification', 'EO-CL-1')->count());
    }

    public function test_redistributing_moves_only_what_arrives_afterwards(): void
    {
        $first = $this->ownBatch('Recría A');
        $second = $this->ownBatch('Recría B');
        $order = $this->store($this->mixedTroop([$this->destination(1, $first->id), $this->destination(2, $first->id)]))->json('order');
        $loaded = $this->loadDte($order['id'], 2);
        $this->receiveOn($loaded, [['caravana' => 'EO-RD-1', 'sex' => 'M', 'breed_position' => 1]]);

        $redistributed = $this->apiAs('PUT', "/entry-orders/{$order['id']}/destinations", [
            'destinations' => [$this->destination(1, $second->id), $this->destination(2, $second->id)],
        ])->assertOk()->json('order');

        $this->assertSame([$second->id, $second->id], array_column(array_column($redistributed['destinations'], 'batch'), 'id'));
        $this->receiveOn($redistributed, [['caravana' => 'EO-RD-2', 'sex' => 'M', 'breed_position' => 1]]);

        $this->assertSame($first->id, (int) Caravan::where('identification', 'EO-RD-1')->value('batch_id'));
        $this->assertSame($second->id, (int) Caravan::where('identification', 'EO-RD-2')->value('batch_id'));
    }

    public function test_leaving_animals_without_destination_creates_the_transit_batch(): void
    {
        $own = $this->ownBatch('Recría 2026');
        // Without an auction there is no name composed for a transit batch.
        $order = $this->store($this->mixedTroop([$this->destination(1, $own->id), $this->destination(2, $own->id)], ['auction_number' => null]))->json('order');
        $this->assertNull($order['batch_name']);

        $this->apiAs('PUT', "/entry-orders/{$order['id']}/destinations", ['destinations' => [$this->destination(1, $own->id)]])
            ->assertStatus(422)
            ->assertJsonPath('code', 'TRANSIT_BATCH_NAME_MISSING');

        $redistributed = $this->apiAs('PUT', "/entry-orders/{$order['id']}/destinations", [
            'destinations' => [$this->destination(1, $own->id)],
            'transit_batch_name' => 'Tránsito vaquillonas',
        ])->assertOk()->json('order');

        $this->assertSame('Tránsito vaquillonas', $redistributed['batch']['name']);
        $this->assertTrue($redistributed['needs_transit_batch']);
    }

    public function test_a_draft_that_stops_being_mixed_drops_the_destination_of_the_sex_it_no_longer_brings(): void
    {
        $males = $this->ownBatch('Machos');
        $females = $this->ownBatch('Hembras');
        $draft = $this->store($this->troop([
            'destinations' => [$this->destination(1, $males->id, 'M'), $this->destination(1, $females->id, 'H')],
        ]), false)->assertCreated()->json('order');

        $result = $this->apiAs('PUT', "/entry-orders/{$draft['id']}", [
            ...$this->troop([
                'sex_composition' => 'MALE',
                'male_count' => null,
                'female_count' => null,
                'destinations' => [$this->destination(1, $males->id, 'M'), $this->destination(1, $females->id, 'H')],
            ]),
            'confirm' => false,
        ])->assertOk()->json();

        // The rule of the males becomes the rule of the whole line; the one of the females is gone.
        $this->assertSame([['category_position' => 1, 'sex_scope' => 'ALL', 'batch' => ['id' => $males->id, 'name' => 'Machos'], 'label' => 'Ternero']], $result['order']['destinations']);
        $this->assertSame('DESTINATION_RULES_DROPPED', $result['warnings'][0]['code']);
    }

    public function test_cancelling_an_order_without_transit_batch_leaves_the_own_batches_alone(): void
    {
        $own = $this->ownBatch('Recría 2026');
        $order = $this->store($this->mixedTroop([$this->destination(1, $own->id), $this->destination(2, $own->id)]))->json('order');

        $this->apiAs('POST', "/entry-orders/{$order['id']}/cancel", ['reason' => 'No se concretó'])->assertOk();

        $this->assertTrue((bool) $own->fresh()->is_active);
    }

    public function test_registering_an_entry_routes_its_animals_in_one_step(): void
    {
        $own = $this->ownBatch('Recría 2026');

        $this->apiAs('POST', '/entry-orders/register', [
            ...$this->troop([
                'head_count' => 2,
                'sex_composition' => 'MALE',
                'male_count' => null,
                'female_count' => null,
                'auction_number' => null,
                'destinations' => [$this->destination(1, $own->id)],
            ]),
            'dte' => $this->registerDte($this->animals('RG', 2)),
        ])->assertCreated()->assertJsonPath('order.batch', null);

        $this->assertSame(2, Caravan::where('batch_id', $own->id)->count());
    }

    public function test_an_own_batch_lists_the_purchases_that_bring_animals_to_it(): void
    {
        $males = $this->ownBatch('Recría Macho 2026');
        $females = $this->ownBatch('Reposición Vaquillonas');
        $order = $this->store($this->mixedTroop([
            $this->destination(1, $males->id, 'M'),
            $this->destination(1, $females->id, 'H'),
            $this->destination(2, $females->id),
        ]))->json('order');

        $incoming = $this->apiAs('GET', "/batches/{$females->id}")->json('incoming_entry_orders');

        $this->assertCount(1, $incoming);
        $this->assertSame($order['code'], $incoming[0]['code']);
        $this->assertSame(['Ternero · hembras', 'Vaquillona'], $incoming[0]['rules']);
        $this->assertFalse($incoming[0]['single_destination']);
    }
}
