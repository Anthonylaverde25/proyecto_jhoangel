<?php

declare(strict_types=1);

namespace Tests\Feature\Insemination;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** "Cargar inseminaciones", the automatic consumption and the void (semen S-B, IA6-IA9, IA14). */
class RegisterInseminationsTest extends InseminationTestCase
{
    public function test_one_consumption_per_tank_charged_to_the_batch_with_a_line_per_lot(): void
    {
        [$a, $b, $c] = $this->cows(3);
        $orderId = (int) $this->startAi([$a, $b, $c])->assertStatus(201)->json('id');
        $other = $this->newWarehouse('TER-' . random_int(1000, 9999), 'Termo 2 ' . Str::random(4), 'CRYO_TANK');

        $response = $this->inseminate($orderId, [
            $this->row($a, $this->lotA, 2),
            $this->row($b, $this->lotB, 1),
            $this->row($c, null, 1, warehouse: $other),
        ])->assertStatus(201);

        $documents = $response->json('documents');
        $this->assertCount(2, $documents);
        $inTank = collect($documents)->firstWhere('warehouse.id', $this->tank) ?? $documents[0];
        $this->assertSame('CONSUMPTION', $inTank['type']);
        $this->assertSame($this->rodeo->id, $inTank['batch']['id'] ?? $inTank['batch_id'] ?? null);
        $this->assertSame(2, $inTank['heads_treated']);
        $this->assertCount(2, $inTank['lines']);
        $this->assertSame($orderId, $inTank['service_order']['id']);

        $this->assertSame('18.0000', $this->lotBalance($this->lotA, $this->tank));
        $this->assertSame('2.0000', $this->lotBalance($this->lotB, $this->tank));
        // The other tank had no straws: negative, without lot, with its "Identificar partida" notice.
        $this->assertNotEmpty($response->json('incidents'));
        $this->assertSame(3, DB::table('service_order_inseminations')->where('service_order_id', $orderId)->whereNotNull('item_document_line_id')->count());

        $summary = $this->order($orderId)['insemination_summary'];
        $this->assertSame(3, $summary['inseminated']);
        $this->assertSame([], $this->reconcile());
    }

    public function test_every_broken_rule_comes_back_on_its_cell_and_nothing_stays(): void
    {
        [$a, $b] = $this->cows(2);
        $stranger = $this->cows(1, $this->bullPaddock)[0];
        $orderId = (int) $this->startAi([$a, $b])->assertStatus(201)->json('id');

        $this->inseminate($orderId, [
            $this->row($a, $this->lotA, date: now()->subDays(40)->toDateString()),
            $this->row($b, $this->lotA, date: now()->addDay()->toDateString()),
            $this->row($stranger, $this->lotA),
            $this->row($a, $this->lotA, warehouse: $this->defaultWarehouseId(), date: now()->subDays(3)->toDateString()),
        ])->assertStatus(422)->assertJsonValidationErrors([
            'rows.0.inseminated_on', 'rows.1.inseminated_on', 'rows.2.female_caravan_id', 'rows.3.warehouse_id',
        ]);
        $this->assertSame(0, DB::table('service_order_inseminations')->where('service_order_id', $orderId)->count());

        // A lot without straws enough: on the lot cell of its rows.
        $this->inseminate($orderId, [$this->row($a, $this->lotB, 2), $this->row($b, $this->lotB, 2)])
            ->assertStatus(422)->assertJsonValidationErrors(['rows.0.semen_lot_id', 'rows.1.semen_lot_id']);
        $this->assertSame('3.0000', $this->lotBalance($this->lotB, $this->tank));

        // The same female twice the same day.
        $this->inseminate($orderId, [$this->row($a, $this->lotA)])->assertStatus(201);
        $this->inseminate($orderId, [$this->row($a, $this->lotA)])->assertStatus(422)->assertJsonValidationErrors(['rows.0.inseminated_on']);
    }

    public function test_idempotent_retry(): void
    {
        [$a] = $this->cows(1);
        $orderId = (int) $this->startAi([$a])->assertStatus(201)->json('id');
        $key = (string) Str::uuid();

        $this->inseminate($orderId, [$this->row($a, $this->lotA)], $key)->assertStatus(201);
        $this->inseminate($orderId, [$this->row($a, $this->lotA)], $key)->assertStatus(200);
        $this->assertSame(1, DB::table('service_order_inseminations')->where('service_order_id', $orderId)->count());
        $this->assertSame('19.0000', $this->lotBalance($this->lotA, $this->tank));
    }

    public function test_void_reverses_the_load_and_reissues_the_rest(): void
    {
        [$a, $b] = $this->cows(2);
        $orderId = (int) $this->startAi([$a, $b])->assertStatus(201)->json('id');
        $this->inseminate($orderId, [$this->row($a, $this->lotA, 2), $this->row($b, $this->lotA, 1)])->assertStatus(201);
        $this->assertSame('17.0000', $this->lotBalance($this->lotA, $this->tank));
        $first = (int) DB::table('item_documents')->where('service_order_id', $orderId)->value('id');

        // The consumption is not reversed from the stock module (IA14).
        $this->reverse($first)->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'anulá la inseminación'));

        $voided = (int) DB::table('service_order_inseminations')->where('service_order_id', $orderId)->where('female_caravan_id', $a->id)->value('id');
        $this->apiAs('POST', "/service-orders/{$orderId}/inseminations/{$voided}/void", ['reason' => 'Caravana equivocada', 'idempotency_key' => (string) Str::uuid()])
            ->assertOk()
            ->assertJsonPath('insemination_summary.inseminated', 1)
            ->assertJsonPath('insemination_summary.not_inseminated', 1);

        $this->assertSame('19.0000', $this->lotBalance($this->lotA, $this->tank));
        $this->assertNotNull(DB::table('item_documents')->where('reverses_document_id', $first)->value('id'));
        $reissued = DB::table('item_documents')->where('service_order_id', $orderId)->where('id', '>', $first)->first();
        $this->assertNotNull($reissued);
        $this->assertSame(1, (int) $reissued->heads_treated);
        $remaining = DB::table('service_order_inseminations')->where('female_caravan_id', $b->id)->value('item_document_line_id');
        $this->assertSame((int) $reissued->id, (int) DB::table('item_document_lines')->where('id', $remaining)->value('item_document_id'));

        // Voiding the last one leaves nothing to reissue.
        $last = (int) DB::table('service_order_inseminations')->where('female_caravan_id', $b->id)->value('id');
        $this->apiAs('POST', "/service-orders/{$orderId}/inseminations/{$last}/void", ['reason' => 'Error', 'idempotency_key' => (string) Str::uuid()])->assertOk();
        $this->assertSame('20.0000', $this->lotBalance($this->lotA, $this->tank));
        $this->assertSame([], $this->reconcile());
    }

    public function test_coverage_counts_what_open_orders_still_expect(): void
    {
        $cows = $this->cows(4);
        $orderId = (int) $this->startAi($cows, array_fill_keys(array_map(fn ($c) => $c->id, $cows), $this->semenItem))->assertStatus(201)->json('id');
        $this->inseminate($orderId, [$this->row($cows[0], $this->lotA)])->assertStatus(201);

        $coverage = $this->apiAs('GET', "/semen/coverage?item_ids[]={$this->semenItem}&extra[{$this->semenItem}]=25")->assertOk()->json(0);
        $this->assertSame(3, $coverage['committed']);
        $this->assertSame(25, $coverage['extra']);
        $this->assertEquals(22.0, $coverage['balance']);
        $this->assertEquals(6.0, $coverage['shortage']);
    }

    public function test_the_item_kardex_shows_the_lot_of_each_movement(): void
    {
        [$a, $b] = $this->cows(2);
        $orderId = (int) $this->startAi([$a, $b])->assertStatus(201)->json('id');
        $this->inseminate($orderId, [$this->row($a, $this->lotA), $this->row($b, null)])->assertStatus(201);

        $rows = collect($this->apiAs('GET', "/items/{$this->semenItem}/kardex")->assertOk()->json('data'));
        $consumptions = $rows->where('document_type', 'CONSUMPTION');
        $this->assertEqualsCanonicalizing(['L-A', null], $consumptions->map(fn (array $r) => $r['semen_lot']['code'] ?? null)->values()->all());
        $this->assertSame('L-B', $rows->where('document_type', 'SEMEN_RECEIPT')->pluck('semen_lot.code')->sort()->values()->first(fn ($code) => $code === 'L-B'));
    }

    public function test_cancel_needs_no_live_insemination(): void
    {
        [$a] = $this->cows(1);
        $orderId = (int) $this->startAi([$a])->assertStatus(201)->json('id');
        $this->inseminate($orderId, [$this->row($a, $this->lotA)])->assertStatus(201);

        $this->apiAs('PATCH', "/service-orders/{$orderId}/status", ['status' => 'CANCELLED'])->assertStatus(422);
        $id = (int) DB::table('service_order_inseminations')->where('service_order_id', $orderId)->value('id');
        $this->apiAs('POST', "/service-orders/{$orderId}/inseminations/{$id}/void", ['reason' => 'Error', 'idempotency_key' => (string) Str::uuid()])->assertOk();
        $this->apiAs('PATCH', "/service-orders/{$orderId}/status", ['status' => 'CANCELLED'])->assertOk();
    }
}
