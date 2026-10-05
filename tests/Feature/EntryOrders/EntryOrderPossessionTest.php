<?php

declare(strict_types=1);

namespace Tests\Feature\EntryOrders;

use App\Models\Batch;
use App\Models\Caravan;

/**
 * A caravan in transit is ours but not in the field: it does not count as stock nor weight, it
 * cannot go to an own batch, and the chute reports it as in transit.
 */
class EntryOrderPossessionTest extends EntryOrderTestCase
{
    public function test_caravans_in_transit_are_not_counted_in_the_batch(): void
    {
        $order = $this->createOrder(['head_count' => 2, 'sex_composition' => 'MALE', 'male_count' => null, 'female_count' => null])->json('order');
        $loaded = $this->loadDte($order['id'], $this->animals('S', 2, 'M', 1));
        $this->receive($order['id'], ['method' => 'MANUAL', 'dte_id' => $loaded['dtes'][0]['id'], 'received' => $this->lines([$this->caravanIds($loaded)[0]], 200)])
            ->assertOk();

        $batch = Batch::findOrFail($order['batch']['id']);
        $this->assertSame(1, (int) $batch->caravans_count);
        $this->assertEquals(200.0, (float) $batch->current_weight);

        $this->assertSame(1, Caravan::inPossession()->where('batch_id', $batch->id)->count());
        $this->assertSame(1, Caravan::inTransit()->where('batch_id', $batch->id)->count());

        $summary = collect($this->apiAs('GET', '/batches')->json())->firstWhere('id', $batch->id)['entry_order'];
        $this->assertSame(1, $summary['received_count']);
        $this->assertSame(1, $summary['in_transit_count']);
        $this->assertSame(2, $summary['with_dte_count']);
    }

    public function test_a_caravan_in_transit_cannot_be_assigned_to_an_own_batch(): void
    {
        $order = $this->createOrder()->json('order');
        $this->loadDte($order['id'], $this->animals('N', 1));
        $own = Batch::create(['company_id' => $this->company->id, 'name' => 'Propio EO', 'is_active' => true, 'is_confined' => false]);
        $caravan = Caravan::where('identification', 'EO-N-1')->firstOrFail();

        $response = $this->apiAs('POST', '/batches/assign-to-own', [
            'caravan_ids' => [$caravan->id],
            'target_batch_id' => $own->id,
        ]);

        $this->assertGreaterThanOrEqual(400, $response->status());
        $this->assertStringContainsString('en tránsito', (string) $response->json('message'));
        $this->assertSame($order['batch']['id'], (int) $caravan->fresh()->batch_id);
    }

    public function test_the_lookup_marks_a_caravan_in_transit_with_its_order(): void
    {
        $order = $this->createOrder()->json('order');
        $this->loadDte($order['id'], $this->animals('L', 1));

        $result = $this->apiAs('POST', '/caravans/lookup', ['identifications' => ['EO-L-1']])->assertOk()->json();
        $row = collect($result['data'] ?? $result)->firstWhere('identification', 'EO-L-1');

        $this->assertSame('own_company', $row['status']);
        $this->assertTrue($row['in_transit']);
        $this->assertSame($order['code'], $row['entry_order_code']);
    }

    public function test_the_caravan_list_flags_the_ones_in_transit(): void
    {
        $order = $this->createOrder()->json('order');
        $this->loadDte($order['id'], $this->animals('V', 1));

        $list = $this->apiAs('GET', '/caravans?scope=external')->assertOk()->json();
        $row = collect($list['data'] ?? $list)->firstWhere('identification', 'EO-V-1');

        $this->assertTrue($row['in_transit']);
        $this->assertFalse($row['in_possession']);
    }

    public function test_a_caravan_that_will_not_arrive_leaves_the_external_list(): void
    {
        $order = $this->createOrder(['head_count' => 2, 'sex_composition' => 'MALE', 'male_count' => null, 'female_count' => null])->json('order');
        $loaded = $this->loadDte($order['id'], $this->animals('X', 2, 'M', 1));
        [$arrived, $lost] = $this->caravanIds($loaded);
        $this->receive($order['id'], [
            'method' => 'MANUAL',
            'dte_id' => $loaded['dtes'][0]['id'],
            'received' => $this->lines([$arrived], 200),
            'missing' => [$lost],
            'reason' => 'Murió en el viaje',
        ])->assertOk();

        $list = collect($this->apiAs('GET', '/caravans?scope=external')->assertOk()->json());
        $identifications = collect($list->get('data', $list))->pluck('identification');

        $this->assertContains('EO-X-1', $identifications);
        $this->assertNotContains('EO-X-2', $identifications);
    }
}
