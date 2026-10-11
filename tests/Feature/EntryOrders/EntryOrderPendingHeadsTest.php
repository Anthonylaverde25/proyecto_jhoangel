<?php

declare(strict_types=1);

namespace Tests\Feature\EntryOrders;

use App\Models\Batch;
use App\Models\Caravan;
use App\Models\CaravanMovement;
use App\Models\CaravanWeight;
use App\Models\EntryOrderDte;
use App\Models\EntryOrderPendingHead;
use Illuminate\Support\Facades\DB;

/**
 * Head received by count are in their batch the day they arrive, before the chute writes their
 * caravans: each batch counts them as pending identification, and a caravan written later takes
 * one of them and enters with the day it arrived.
 */
class EntryOrderPendingHeadsTest extends EntryOrderTestCase
{
    /**
     * A troop of males to a single own batch, with a DTE of all its head loaded.
     *
     * @return array{order: array<string, mixed>, batch: Batch}
     */
    private function toOneBatch(int $head): array
    {
        $batch = $this->ownBatch('Recría 2026');
        $order = $this->apiAs('POST', '/entry-orders', [
            ...$this->troop([
                'head_count' => $head,
                'sex_composition' => 'MALE',
                'male_count' => null,
                'female_count' => null,
                'destinations' => [$this->destination(1, $batch->id)],
            ]),
            'confirm' => true,
        ])->json('order');

        return ['order' => $this->loadDte($order['id'], $head), 'batch' => $batch];
    }

    /**
     * Calves split by sex to two own batches, with a DTE of all its head loaded.
     *
     * @return array{order: array<string, mixed>, males: Batch, females: Batch}
     */
    private function toTwoBatches(int $males, int $females): array
    {
        $maleBatch = $this->ownBatch('Recría Macho');
        $femaleBatch = $this->ownBatch('Reposición');
        $order = $this->apiAs('POST', '/entry-orders', [
            ...$this->troop([
                'head_count' => $males + $females,
                'male_count' => $males,
                'female_count' => $females,
                'destinations' => [$this->destination(1, $maleBatch->id, 'M'), $this->destination(1, $femaleBatch->id, 'H')],
            ]),
            'confirm' => true,
        ])->json('order');

        return ['order' => $this->loadDte($order['id'], $males + $females), 'males' => $maleBatch, 'females' => $femaleBatch];
    }

    /**
     * @param array<string, mixed> $order
     * @param array<string, mixed> $extra
     * @return \Illuminate\Testing\TestResponse
     */
    private function receiveHeads(array $order, int $heads, array $extra = [])
    {
        return $this->receive($order['id'], ['dte_id' => $order['dtes'][0]['id'], 'received_head_count' => $heads, ...$extra]);
    }

    /**
     * @return array<string, mixed>
     */
    private function batchView(Batch $batch): array
    {
        return $this->apiAs('GET', "/batches/{$batch->id}")->json();
    }

    public function test_head_received_by_count_go_into_the_only_batch_possible(): void
    {
        ['order' => $order, 'batch' => $batch] = $this->toOneBatch(10);

        $received = $this->receiveHeads($order, 10)->assertOk()->json('order');

        $this->assertSame([['batch' => ['id' => $batch->id, 'name' => 'Recría 2026'], 'head_count' => 10]], $received['pending_by_batch']);
        $view = $this->batchView($batch);
        $this->assertSame(0, $view['caravans_count'] ?? 0);
        $this->assertSame(10, $view['pending_identification_count']);
        $this->assertSame(10, $view['head_count']);
        $this->assertSame(10, $view['incoming_entry_orders'][0]['pending_count']);
        $this->assertTrue($view['incoming_entry_orders'][0]['single_destination']);
    }

    public function test_head_going_to_several_batches_declare_how_many_went_to_each(): void
    {
        ['order' => $order, 'males' => $males, 'females' => $females] = $this->toTwoBatches(6, 4);

        $this->receiveHeads($order, 10)->assertStatus(422)->assertJsonPath('header_errors.0.code', 'PENDING_SPLIT_REQUIRED');
        $this->receiveHeads($order, 10, ['heads_by_batch' => [
            ['batch_id' => $males->id, 'head_count' => 6],
            ['batch_id' => $females->id, 'head_count' => 3],
        ]])->assertStatus(422)->assertJsonPath('header_errors.0.code', 'PENDING_SPLIT_MISMATCH');

        $this->receiveHeads($order, 10, ['heads_by_batch' => [
            ['batch_id' => $males->id, 'head_count' => 6],
            ['batch_id' => $females->id, 'head_count' => 4],
        ]])->assertOk();

        $this->assertSame(6, $this->batchView($males)['head_count']);
        $this->assertSame(4, $this->batchView($females)['head_count']);
    }

    public function test_caravans_written_with_the_head_are_not_pending(): void
    {
        ['order' => $order, 'batch' => $batch] = $this->toOneBatch(10);

        $this->receiveHeads($order, 10, ['animals' => $this->animals('WH', 3)])->assertOk();

        $view = $this->batchView($batch);
        $this->assertSame(3, $view['caravans_count']);
        $this->assertSame(7, $view['pending_identification_count']);
        $this->assertSame(10, $view['head_count']);
    }

    public function test_a_caravan_written_later_takes_a_head_of_its_own_batch_and_the_day_it_arrived(): void
    {
        ['order' => $order, 'males' => $males, 'females' => $females] = $this->toTwoBatches(6, 4);
        $arrival = now()->subDay()->toDateString();

        $this->receiveHeads($order, 10, [
            'received_at' => $arrival,
            'heads_by_batch' => [['batch_id' => $males->id, 'head_count' => 6], ['batch_id' => $females->id, 'head_count' => 4]],
        ])->assertOk();

        $this->receive($order['id'], ['dte_id' => $order['dtes'][0]['id'], 'animals' => $this->animals('LT', 2, 'H')])->assertOk();

        $this->assertSame(2, $this->batchView($females)['caravans_count']);
        $this->assertSame(2, $this->batchView($females)['pending_identification_count']);
        $this->assertSame(6, $this->batchView($males)['pending_identification_count']);

        // Written today, arrived yesterday: the caravan, its movement and its weighing say yesterday.
        $caravan = Caravan::where('identification', 'EO-LT-1')->firstOrFail();
        $this->assertSame($arrival, substr((string) $caravan->entry_date, 0, 10));
        $this->assertSame($arrival, substr((string) CaravanMovement::where('caravan_id', $caravan->id)->value('movement_date'), 0, 10));
        $this->assertSame($arrival, substr((string) CaravanWeight::where('caravan_id', $caravan->id)->value('weighing_date'), 0, 10));
    }

    public function test_a_split_declared_wrong_is_corrected_by_the_caravans(): void
    {
        ['order' => $order, 'males' => $males, 'females' => $females] = $this->toTwoBatches(6, 4);

        // Declared 5 males and 5 females; they were 6 and 4.
        $this->receiveHeads($order, 10, ['heads_by_batch' => [
            ['batch_id' => $males->id, 'head_count' => 5],
            ['batch_id' => $females->id, 'head_count' => 5],
        ]])->assertOk();

        $result = $this->receive($order['id'], ['dte_id' => $order['dtes'][0]['id'], 'animals' => $this->animals('SP', 6, 'M')])->assertOk()->json();

        $this->assertContains('PENDING_SPLIT_CORRECTED', array_column($result['warnings'], 'code'));
        $this->assertSame(6, $this->batchView($males)['caravans_count']);
        $this->assertSame(0, $this->batchView($males)['pending_identification_count']);
        $this->assertSame(4, $this->batchView($females)['pending_identification_count']);
        $this->assertSame(4, (int) EntryOrderDte::findOrFail($order['dtes'][0]['id'])->uncaravaned_head_count);
    }

    public function test_head_that_will_never_get_a_caravan_are_written_off(): void
    {
        ['order' => $order, 'batch' => $batch] = $this->toOneBatch(3);
        $this->receiveHeads($order, 3, ['animals' => $this->animals('WO', 2)])->assertOk();

        $this->apiAs('POST', "/entry-orders/{$order['id']}/pending-heads/write-off", [
            'dte_id' => $order['dtes'][0]['id'], 'batch_id' => $batch->id, 'head_count' => 2, 'reason' => 'Murió',
        ])->assertStatus(422)->assertJsonPath('code', 'PENDING_WRITE_OFF_EXCEEDS');

        $closed = $this->apiAs('POST', "/entry-orders/{$order['id']}/pending-heads/write-off", [
            'dte_id' => $order['dtes'][0]['id'], 'batch_id' => $batch->id, 'head_count' => 1, 'reason' => 'Murió antes de caravanearse',
        ])->assertOk()->json();

        $this->assertSame('CLOSED_INCOMPLETE', $closed['status']);
        $this->assertSame(3, $closed['received_count']);
        $this->assertSame(0, $closed['in_transit_count']);
        $this->assertSame(1, $closed['written_off_count']);
        $this->assertSame('HEAD_WRITTEN_OFF', $closed['incidents'][0]['type']);
        $this->assertStringContainsString('Murió antes de caravanearse', (string) $closed['closing_reason']);
        $this->assertSame(0, $this->batchView($batch)['pending_identification_count']);
    }

    public function test_a_batch_adds_up_the_pending_head_of_every_order(): void
    {
        ['order' => $first, 'batch' => $batch] = $this->toOneBatch(4);
        $this->receiveHeads($first, 4)->assertOk();

        $second = $this->apiAs('POST', '/entry-orders', [
            ...$this->troop(['head_count' => 3, 'sex_composition' => 'MALE', 'male_count' => null, 'female_count' => null, 'destinations' => [$this->destination(1, $batch->id)]]),
            'confirm' => true,
        ])->json('order');
        $second = $this->loadDte($second['id'], 3);
        $this->receiveHeads($second, 3)->assertOk();

        $view = $this->batchView($batch);
        $this->assertSame(7, $view['pending_identification_count']);
        $this->assertCount(2, $view['incoming_entry_orders']);

        $listed = collect($this->apiAs('GET', '/batches?scope=own')->json())->firstWhere('id', $batch->id);
        $this->assertSame(7, $listed['head_count']);
    }

    public function test_head_received_before_the_migration_stay_pending_in_the_order_batch(): void
    {
        $migration = require database_path('migrations/tenant/2026_10_10_000002_create_entry_order_pending_heads_table.php');

        $order = $this->createOrder(['head_count' => 5, 'sex_composition' => 'MALE', 'male_count' => null, 'female_count' => null])->json('order');
        $loaded = $this->loadDte($order['id'], 5);
        $this->receive($order['id'], ['dte_id' => $loaded['dtes'][0]['id'], 'received_head_count' => 5])->assertOk();

        $migration->down();
        $this->assertSame(5, (int) DB::table('entry_order_dtes')->where('id', $loaded['dtes'][0]['id'])->value('uncaravaned_head_count'));
        $migration->up();

        $pending = EntryOrderPendingHead::where('entry_order_dte_id', $loaded['dtes'][0]['id'])->get();
        $this->assertCount(1, $pending);
        $this->assertSame($order['batch']['id'], $pending[0]->batch_id);
        $this->assertSame(5, $pending[0]->head_count);
    }
}
