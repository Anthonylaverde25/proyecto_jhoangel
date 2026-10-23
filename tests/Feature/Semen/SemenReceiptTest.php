<?php

declare(strict_types=1);

namespace Tests\Feature\Semen;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Ingreso de semen (S14, SE1–SE5, DA2, DA3, DA8). */
class SemenReceiptTest extends SemenTestCase
{
    public function test_entry_creates_items_and_lots_and_values_the_stock(): void
    {
        $rambo = $this->sire('Rambo', 'HBA-1');
        $provider = $this->seller->id;

        $doc = $this->receipt([
            $this->receiptLine($rambo, ' l-2301 ', 40, '18000'),
            $this->receiptLine($rambo, 'SX-77', 20, '45000', 'SEXED_FEMALE'),
        ], ['provider_id' => $provider])->assertStatus(201)
            ->assertJsonPath('type', 'SEMEN_RECEIPT')
            ->assertJsonPath('provider.id', $provider)
            ->assertJsonPath('lines.0.semen_lot.code', 'L-2301')
            ->assertJsonPath('lines.0.item.name', 'Semen · Rambo · Convencional')
            ->json();
        $conventional = $doc['lines'][0]['item']['id'];
        $this->assertNotSame($conventional, $doc['lines'][1]['item']['id'], 'one item per sire and type');

        // Second entry: same item (DA2), a new lot and the same lot written differently (DA3).
        $this->receipt([$this->receiptLine($rambo, 'L-2405', 60, '20000'), $this->receiptLine($rambo, 'L 2301', 1, '18000')])->assertStatus(201);
        $this->receipt([$this->receiptLine($rambo, 'l-2301', 9, '18000')])->assertStatus(201)->assertJsonPath('lines.0.item.id', $conventional);

        $row = $this->semenRow($rambo);
        $this->assertSame(['L 2301', 'L-2301', 'L-2405'], collect($row['lots'])->pluck('code')->all());
        $this->assertSame('49.0000', $this->lotBalance($this->lotId($conventional, 'L-2301'), $this->tank));
        $this->assertSame('110.0000', $row['stocks'][0]['quantity']);
        $this->assertSame([], $this->reconcile());
        $item = $this->apiAs('GET', "/items/{$conventional}")->assertOk();
        $item->assertJsonPath('is_semen', true)->assertJsonPath('unit.code', 'STRAW')->assertJsonPath('semen_profile.semen_type.value', 'CONVENTIONAL');
    }

    public function test_every_broken_rule_answers_at_once_and_nothing_stays(): void
    {
        $rambo = $this->sire('Rambo reglas');
        $general = $this->defaultWarehouseId();
        $before = DB::table('items')->count();

        $this->receipt([
            ['semen_sire_id' => null, 'semen_type' => 'X', 'quantity' => '0', 'unit_cost' => '-1'],
            $this->receiptLine($rambo, 'A-1', 2),
            $this->receiptLine($rambo, 'a-1', 3),
        ], ['warehouse_id' => $general, 'occurred_at' => now()->addDay()->toDateString(), 'provider_id' => 999999])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'occurred_at', 'warehouse_id', 'provider_id',
                'lines.0.semen_sire_id', 'lines.0.semen_type', 'lines.0.quantity', 'lines.0.unit_cost', 'lines.2.lot_code',
            ]);
        $this->assertSame($before, DB::table('items')->count());
        $this->receipt([])->assertStatus(422)->assertJsonValidationErrors('lines');
    }

    public function test_idempotent_and_reactivates_the_item(): void
    {
        $rambo = $this->sire('Rambo idem');
        $key = (string) Str::uuid();
        $first = $this->receipt([$this->receiptLine($rambo, 'L-9', 4)], ['idempotency_key' => $key])->assertStatus(201)->json();
        $this->receipt([$this->receiptLine($rambo, 'L-9', 4)], ['idempotency_key' => $key])->assertOk()->assertJsonPath('id', $first['id']);

        $itemId = $first['lines'][0]['item']['id'];
        DB::table('items')->where('id', $itemId)->update(['is_active' => false]);
        $this->receipt([$this->receiptLine($rambo, 'L-10', 1)])->assertStatus(201)->assertJsonPath('lines.0.item.id', $itemId);
        $this->assertTrue((bool) DB::table('items')->where('id', $itemId)->value('is_active'));
    }

    public function test_reversing_an_entry_needs_its_straws_in_the_lot(): void
    {
        $rambo = $this->sire('Rambo anular');
        $doc = $this->receipt([$this->receiptLine($rambo, 'L-1', 10)])->assertStatus(201)->json();
        $itemId = $doc['lines'][0]['item']['id'];
        $lot = $this->lotId($itemId, 'L-1');

        $consumed = $this->consume([$this->straws($itemId, '3', $lot)], ['warehouse_id' => $this->tank])->assertStatus(201)->json('id');
        $this->reverse($doc['id'])->assertStatus(422);   // SL8 / the ledger: straws already left
        $this->reverse($consumed)->assertStatus(201);
        $this->reverse($doc['id'])->assertStatus(201);
        $this->assertSame('0.0000', $this->lotBalance($lot, $this->tank));
        $this->assertSame([], $this->reconcile());
    }
}
