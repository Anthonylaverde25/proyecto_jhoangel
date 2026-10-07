<?php

declare(strict_types=1);

namespace Tests\Feature\EntryOrders;

use App\Models\Batch;
use App\Models\Caravan;

/**
 * Head still on their way are a count on their DTE, not caravans: the batch only holds — and
 * weighs — the animals received, and every caravan that exists is in the field.
 */
class EntryOrderPossessionTest extends EntryOrderTestCase
{
    public function test_the_batch_counts_the_received_animals_and_the_order_the_head_in_transit(): void
    {
        $order = $this->createOrder(['head_count' => 2, 'sex_composition' => 'MALE', 'male_count' => null, 'female_count' => null])->json('order');
        $loaded = $this->loadDte($order['id'], 2);
        $this->receiveOn($loaded, $this->animals('S', 1, 'M', 1, 200));

        $batch = Batch::findOrFail($order['batch']['id']);
        $this->assertSame(1, (int) $batch->caravans_count);
        $this->assertEquals(200.0, (float) $batch->current_weight);
        $this->assertSame(1, Caravan::inPossession()->where('batch_id', $batch->id)->count());

        $summary = collect($this->apiAs('GET', '/batches')->json())->firstWhere('id', $batch->id)['entry_order'];
        $this->assertSame(1, $summary['received_count']);
        $this->assertSame(1, $summary['in_transit_count']);
        $this->assertSame(2, $summary['with_dte_count']);
    }

    public function test_a_received_caravan_goes_to_an_own_batch_keeping_its_dte(): void
    {
        $order = $this->createOrder(['head_count' => 1, 'sex_composition' => 'MALE', 'male_count' => null, 'female_count' => null])->json('order');
        $this->receiveOn($this->loadDte($order['id'], 1, ['dte_number' => 'DTE-ASSIGN']), $this->animals('P', 1));

        $own = Batch::create(['company_id' => $this->company->id, 'name' => 'Recría Propia EO', 'is_active' => true, 'is_confined' => false]);
        $caravan = Caravan::where('identification', 'EO-P-1')->firstOrFail();

        $this->apiAs('POST', '/batches/assign-to-own', [
            'caravan_ids' => [$caravan->id],
            'target_batch_id' => $own->id,
            'entry_date' => now()->toDateString(),
        ])->assertOk();

        $this->assertSame($own->id, (int) $caravan->fresh()->batch_id);
        $this->assertSame('DTE-ASSIGN', $caravan->fresh()->provenance_metadata['dte_number']);
    }

    public function test_the_lookup_finds_a_received_caravan_as_any_other(): void
    {
        $order = $this->createOrder(['head_count' => 1, 'sex_composition' => 'MALE', 'male_count' => null, 'female_count' => null])->json('order');
        $this->receiveOn($this->loadDte($order['id'], 1), $this->animals('L', 1));

        $result = $this->apiAs('POST', '/caravans/lookup', ['identifications' => ['EO-L-1']])->assertOk()->json();
        $row = collect($result['data'] ?? $result)->firstWhere('identification', 'EO-L-1');

        $this->assertSame('own_company', $row['status']);
        $this->assertArrayNotHasKey('in_transit', $row);
    }
}
