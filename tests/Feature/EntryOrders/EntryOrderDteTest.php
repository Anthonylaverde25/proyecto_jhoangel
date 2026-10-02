<?php

declare(strict_types=1);

namespace Tests\Feature\EntryOrders;

use App\Models\Batch;
use App\Models\Caravan;
use App\Models\CaravanMovement;
use App\Models\CaravanWeight;

/**
 * The caravans of an entry order only enter with a DTE. They inherit what the order declared
 * (category, sex unless both, breed unless several) and the order follows the head they bring.
 */
class EntryOrderDteTest extends EntryOrderTestCase
{
    public function test_a_partial_dte_leaves_the_order_waiting_for_the_next_one(): void
    {
        $order = $this->createOrder()->json('order');

        $response = $this->apiAs('POST', "/entry-orders/{$order['id']}/dtes", $this->dte([
            ...$this->animals('A', 20, 'M', 1),
            ...$this->animals('B', 10, 'H', 2),
        ], ['dte_number' => '012345678']))->assertCreated();

        $loaded = $response->json('order');
        $this->assertSame('PARTIAL', $loaded['status']);
        $this->assertSame(30, $loaded['entered_count']);
        $this->assertSame(10, $loaded['pending_count']);
        $this->assertSame(20, $loaded['entered_male_count']);
        $this->assertSame(10, $loaded['entered_female_count']);
        $this->assertSame('012345678', $loaded['dtes'][0]['dte_number']);
        $this->assertCount(30, $loaded['dtes'][0]['animals']);

        $caravan = Caravan::where('identification', 'EO-A-1')->firstOrFail();
        $this->assertSame($order['batch']['id'], (int) $caravan->batch_id);
        $this->assertSame($this->provider->id, (int) $caravan->provider_id);
        $this->assertSame('01.234.5.67890/00', $caravan->renspa);
        $this->assertSame($this->categoryId('TERNERO'), (int) $caravan->category_id);
        $this->assertSame($this->breedId('Braford'), (int) $caravan->breed_id);
        $this->assertSame($this->colorId('Colorado'), (int) $caravan->color_id);
        $this->assertSame('012345678', $caravan->provenance_metadata['dte_number']);
        $this->assertSame($order['code'], $caravan->provenance_metadata['extra_data']['entry_order_code']);
        $this->assertSame('338', $caravan->provenance_metadata['auction_name']);

        $this->assertSame('PURCHASE', CaravanMovement::where('caravan_id', $caravan->id)->value('type'));
        $this->assertSame(1, CaravanWeight::where('caravan_id', $caravan->id)->count());
        $this->assertSame(30, (int) Batch::findOrFail($order['batch']['id'])->caravans_count);

        $completed = $this->apiAs('POST', "/entry-orders/{$order['id']}/dtes", $this->dte([
            ...$this->animals('C', 5, 'M', 1),
            ...$this->animals('D', 5, 'H', 2),
        ]))->assertCreated()->json('order');

        $this->assertSame('COMPLETED', $completed['status']);
        $this->assertSame(0, $completed['pending_count']);
        $this->assertCount(2, $completed['dtes']);
        $this->assertNotNull($completed['closed_at']);
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
            ->assertJsonPath('order.status', 'COMPLETED')
            ->assertJsonPath('warnings', []);

        $caravan = Caravan::where('identification', 'EO-S-1')->firstOrFail();
        $this->assertSame('M', $caravan->sex->value);
        $this->assertSame($this->breedId('Angus'), (int) $caravan->breed_id);
        $this->assertNull($caravan->entry_weight);
    }

    public function test_every_problem_of_the_dte_is_reported_by_row_and_nothing_is_written(): void
    {
        $order = $this->createOrder()->json('order');
        Caravan::create(['company_id' => $this->company->id, 'identification' => 'EO-EXISTS', 'sex' => 'M', 'category_id' => $this->categoryId('TERNERO')]);

        $response = $this->apiAs('POST', "/entry-orders/{$order['id']}/dtes", $this->dte([
            ['caravana' => 'EO-EXISTS', 'sex' => 'M', 'breed_position' => 1, 'weight' => 180],
            ['caravana' => 'EO-DUP', 'sex' => 'M', 'breed_position' => 1, 'weight' => 180],
            ['caravana' => 'EO-DUP', 'sex' => 'H', 'breed_position' => 1, 'weight' => 180],
            ['caravana' => 'EO-NOSEX', 'sex' => null, 'breed_position' => 1, 'weight' => 180],
            ['caravana' => 'EO-BREED', 'sex' => 'H', 'breed_position' => 3, 'weight' => 180],
            ['caravana' => 'EO-ZERO', 'sex' => 'H', 'breed_position' => 1, 'weight' => 0],
        ]))->assertStatus(422);

        $codes = array_column($response->json('row_errors'), 'code', 'row');
        $this->assertSame('CARAVAN_EXISTS', $codes[0]);
        $this->assertSame('CARAVAN_DUPLICATED', $codes[2]);
        $this->assertSame('SEX_MISSING', $codes[3]);
        $this->assertSame('BREED_UNKNOWN', $codes[4]);
        $this->assertSame('WEIGHT_INVALID', $codes[5]);

        $this->assertSame(0, Caravan::where('identification', 'like', 'EO-DUP%')->count());
        $this->assertSame('AWAITING_DTE', $this->apiAs('GET', "/entry-orders/{$order['id']}")->json('status'));
    }

    public function test_more_head_than_bought_is_refused(): void
    {
        $order = $this->createOrder(['head_count' => 2, 'male_count' => 1, 'female_count' => 1])->json('order');

        $response = $this->apiAs('POST', "/entry-orders/{$order['id']}/dtes", $this->dte([
            ...$this->animals('X', 2, 'M', 1),
            ...$this->animals('Y', 1, 'H', 1),
        ]))->assertStatus(422);

        $codes = array_column($response->json('header_errors'), 'code');
        $this->assertContains('HEAD_COUNT_EXCEEDED', $codes);
        $this->assertContains('SEX_COUNT_EXCEEDED', $codes);
    }

    public function test_the_same_dte_cannot_be_loaded_twice(): void
    {
        $first = $this->createOrder()->json('order');
        $second = $this->createOrder(['auction_number' => '339'])->json('order');

        $this->apiAs('POST', "/entry-orders/{$first['id']}/dtes", $this->dte($this->animals('F', 1), ['dte_number' => 'dte-777']))
            ->assertCreated();

        $response = $this->apiAs('POST', "/entry-orders/{$second['id']}/dtes", $this->dte($this->animals('G', 1), ['dte_number' => 'DTE-777']))
            ->assertStatus(422);

        $this->assertSame('DTE_ALREADY_LOADED', $response->json('header_errors.0.code'));
        $this->assertStringContainsString($first['code'], $response->json('header_errors.0.message'));
    }

    public function test_dates_cannot_be_future_or_before_the_purchase(): void
    {
        $order = $this->createOrder()->json('order');

        $this->apiAs('POST', "/entry-orders/{$order['id']}/dtes", $this->dte($this->animals('T', 1), ['entered_at' => now()->addDay()->toDateString()]))
            ->assertStatus(422);

        $response = $this->apiAs('POST', "/entry-orders/{$order['id']}/dtes", $this->dte($this->animals('T', 1), [
            'dte_date' => now()->subDays(5)->toDateString(),
            'entered_at' => now()->subDays(4)->toDateString(),
        ]))->assertStatus(422);

        $this->assertSame('ENTERED_BEFORE_PURCHASE', $response->json('header_errors.0.code'));
    }

    public function test_weights_outside_the_declared_range_and_blank_breeds_are_warned_not_blocked(): void
    {
        $order = $this->createOrder()->json('order');

        $response = $this->apiAs('POST', "/entry-orders/{$order['id']}/dtes", $this->dte([
            ['caravana' => 'EO-HEAVY', 'sex' => 'M', 'breed_position' => 1, 'weight' => 260],
            ['caravana' => 'EO-NOBREED', 'sex' => 'H', 'breed_position' => null, 'weight' => 180],
        ]))->assertCreated();

        $this->assertSame(['WEIGHT_OUT_OF_RANGE', 'BREED_UNDECLARED'], array_column($response->json('warnings'), 'code'));
        $this->assertNull(Caravan::where('identification', 'EO-NOBREED')->value('breed_id'));
    }

    public function test_an_order_with_caravans_is_not_cancelled_but_closed_incomplete(): void
    {
        $order = $this->createOrder(['head_count' => 3, 'male_count' => 2, 'female_count' => 1])->json('order');
        $this->apiAs('POST', "/entry-orders/{$order['id']}/dtes", $this->dte($this->animals('L', 2, 'M', 1)))->assertCreated();

        $this->apiAs('POST', "/entry-orders/{$order['id']}/cancel", ['reason' => 'No vino'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'INVALID_STATE_TRANSITION');

        $this->apiAs('POST', "/entry-orders/{$order['id']}/close-incomplete", ['reason' => 'Murió una ternera en el viaje'])
            ->assertOk()
            ->assertJsonPath('status', 'CLOSED_INCOMPLETE');

        $this->apiAs('POST', "/entry-orders/{$order['id']}/dtes", $this->dte($this->animals('M', 1, 'H', 1)))
            ->assertStatus(422)
            ->assertJsonPath('code', 'ENTRY_ORDER_NOT_ACCEPTING_DTE');
    }

    public function test_the_dte_survives_the_assignment_to_an_own_batch(): void
    {
        $order = $this->createOrder()->json('order');
        $this->apiAs('POST', "/entry-orders/{$order['id']}/dtes", $this->dte($this->animals('P', 1), ['dte_number' => 'DTE-ASSIGN']))->assertCreated();

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
