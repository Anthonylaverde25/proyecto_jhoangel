<?php

declare(strict_types=1);

namespace Tests\Feature\Semen;

use Illuminate\Support\Facades\DB;

/** Pajuelas sin identificar y la novedad "Identificar partida" (SL6, SU1–SU8). */
class SemenUnidentifiedTest extends SemenTestCase
{
    public function test_entry_without_lot_is_pending_until_identified_in_parts(): void
    {
        $cirilo = $this->sire('Don Cirilo sin partida');
        $doc = $this->receipt([$this->receiptLine($cirilo, null, 25, '15000')])->assertStatus(201)->json();
        $item = $doc['lines'][0]['item']['id'];
        $this->assertNull($doc['lines'][0]['semen_lot']);
        $this->assertSame('UNIDENTIFIED_SEMEN_LOT', $doc['incidents_raised'][0]['type']);

        // SU2: the inventory shows it; SU6: it does not move until identified.
        $this->assertSame('25.0000', $this->semenRow($cirilo)['unidentified'][0]['pending_in']);
        $this->consume([$this->straws($item, '1', null)], ['warehouse_id' => $this->tank])->assertStatus(422)->assertJsonValidationErrors('lines.0.semen_lot_id');

        $entry = $this->apiAs('GET', "/semen/unidentified?item_id={$item}")->assertOk()->json('0');
        $this->assertSame('25.0000', $entry['pending_quantity']);

        // SU3: in parts, to new or existing lots; never more than pending.
        $this->apiAs('POST', "/semen/unidentified/{$entry['id']}/identify", ['lots' => [['lot_code' => 'dc-10', 'quantity' => '30']]])->assertStatus(422)->assertJsonValidationErrors('lots');
        $this->apiAs('POST', "/semen/unidentified/{$entry['id']}/identify", ['lots' => [['lot_code' => 'dc-10', 'quantity' => '15']]])->assertOk()
            ->assertJsonPath('pending_quantity', '10.0000')->assertJsonPath('status', 'OPEN');
        $dc10 = $this->lotId($item, 'DC-10');
        $this->assertSame('15.0000', $this->lotBalance($dc10, $this->tank));
        $this->assertSame([], $this->reconcile());
        $this->assertSame(1, DB::table('stock_incidents')->where('item_id', $item)->where('type', 'UNIDENTIFIED_SEMEN_LOT')->where('status', 'OPEN')->count());

        // SU5: the notice closes when nothing is pending.
        $this->apiAs('POST', "/semen/unidentified/{$entry['id']}/identify", ['lots' => [['semen_lot_id' => $dc10, 'quantity' => '4'], ['lot_code' => 'DC-11', 'quantity' => '6']]])
            ->assertOk()->assertJsonPath('status', 'RESOLVED');
        $this->assertSame(0, DB::table('stock_incidents')->where('item_id', $item)->where('type', 'UNIDENTIFIED_SEMEN_LOT')->where('status', 'OPEN')->count());
        $this->assertSame([], $this->semenRow($cirilo)['unidentified']);
        $this->assertSame([], $this->reconcile());

        // The notice is not closed by hand.
        $incident = (int) DB::table('stock_incidents')->where('item_id', $item)->where('type', 'UNIDENTIFIED_SEMEN_LOT')->value('id');
        $this->apiAs('PATCH', "/stock-incidents/{$incident}/resolve", ['resolution' => 'a mano'])->assertStatus(422);
    }

    public function test_two_entries_share_one_notice_and_reversal_voids_the_pending(): void
    {
        $cirilo = $this->sire('Cirilo dos ingresos');
        $first = $this->receipt([$this->receiptLine($cirilo, null, 10)])->assertStatus(201)->json();
        $this->receipt([$this->receiptLine($cirilo, null, 5)])->assertStatus(201);
        $item = $first['lines'][0]['item']['id'];
        $this->assertSame(1, DB::table('stock_incidents')->where('item_id', $item)->where('type', 'UNIDENTIFIED_SEMEN_LOT')->where('status', 'OPEN')->count());
        $this->assertSame('15.0000', $this->semenRow($cirilo)['unidentified'][0]['pending_in']);

        // SU8: reversing an entry with part identified takes it back from the lot.
        $entry = collect($this->apiAs('GET', "/semen/unidentified?item_id={$item}")->json())->firstWhere('document.id', $first['id']);
        $this->apiAs('POST', "/semen/unidentified/{$entry['id']}/identify", ['lots' => [['lot_code' => 'Z-1', 'quantity' => '4']]])->assertOk();
        $this->reverse($first['id'])->assertStatus(201);
        $this->assertSame('0.0000', $this->lotBalance($this->lotId($item, 'Z-1'), $this->tank));
        $this->assertSame('5.0000', $this->semenRow($cirilo)['unidentified'][0]['pending_in']);
        $this->assertSame([], $this->reconcile());
    }
}
