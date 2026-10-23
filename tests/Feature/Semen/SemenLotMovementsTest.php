<?php

declare(strict_types=1);

namespace Tests\Feature\Semen;

/** La partida en transferencias, consumos y conteos (SL1, SL2, SL4, DA4, DA5). */
class SemenLotMovementsTest extends SemenTestCase
{
    public function test_transfer_moves_the_lot_between_tanks(): void
    {
        $rambo = $this->sire('Rambo mov');
        $doc = $this->receipt([$this->receiptLine($rambo, 'L-1', 40), $this->receiptLine($rambo, 'L-2', 10)])->assertStatus(201)->json();
        $item = $doc['lines'][0]['item']['id'];
        [$l1, $l2] = [$this->lotId($item, 'L-1'), $this->lotId($item, 'L-2')];
        $field = $this->newWarehouse('TER-' . random_int(100, 999), 'Termo de campo test', 'CRYO_TANK');

        // SL1: a semen line names its lot; DA4: two lots of one item are fine, the same lot twice is not.
        $this->transfer($this->tank, $field, [$this->straws($item, '5', null)])->assertStatus(422)->assertJsonValidationErrors('lines.0.semen_lot_id');
        $this->transfer($this->tank, $field, [$this->straws($item, '5', $l1), $this->straws($item, '1', $l1)])->assertStatus(422)->assertJsonValidationErrors('lines.1.item_id');
        $this->transfer($this->tank, $field, [$this->straws($item, '5', $l1), $this->straws($item, '2', $l2)])->assertStatus(201)
            ->assertJsonPath('lines.0.semen_lot.code', 'L-1');

        $this->assertSame(['35.0000', '5.0000', '8.0000', '2.0000'], [
            $this->lotBalance($l1, $this->tank), $this->lotBalance($l1, $field), $this->lotBalance($l2, $this->tank), $this->lotBalance($l2, $field),
        ]);
        // SL4: never more than the lot holds in the origin.
        $this->transfer($field, $this->tank, [$this->straws($item, '6', $l1)])->assertStatus(422)->assertJsonValidationErrors('lines.0.semen_lot_id');
        $this->assertSame([], $this->reconcile());
    }

    public function test_lot_of_another_semen_and_lot_on_other_items_are_refused(): void
    {
        $a = $this->sire('Toro A');
        $b = $this->sire('Toro B');
        $docA = $this->receipt([$this->receiptLine($a, 'X-1', 5)])->assertStatus(201)->json();
        $docB = $this->receipt([$this->receiptLine($b, 'Y-1', 5)])->assertStatus(201)->json();
        $itemA = $docA['lines'][0]['item']['id'];
        $lotB = $this->lotId($docB['lines'][0]['item']['id'], 'Y-1');
        [$other] = $this->stockedItem();
        $this->initialStockIn($this->tank, $other, '100', '2')->assertStatus(201);

        $this->consume([$this->straws($itemA, '1', $lotB)], ['warehouse_id' => $this->tank])->assertStatus(422)->assertJsonValidationErrors('lines.0.semen_lot_id');
        $this->consume([$this->stockLine($other, '1') + ['semen_lot_id' => $lotB]], ['warehouse_id' => $this->tank])->assertStatus(422)->assertJsonValidationErrors('lines.0.semen_lot_id');
    }

    public function test_semen_is_counted_per_lot_in_straws(): void
    {
        $rambo = $this->sire('Rambo conteo');
        $doc = $this->receipt([$this->receiptLine($rambo, 'L-1', 30), $this->receiptLine($rambo, 'L-2', 20)])->assertStatus(201)->json();
        $item = $doc['lines'][0]['item']['id'];
        $l1 = $this->lotId($item, 'L-1');

        $this->countLot($this->tank, $item, null, '29', '30')->assertStatus(422)->assertJsonValidationErrors('semen_lot_id');
        $this->countLot($this->tank, $item, $l1, '29', '50')->assertStatus(409);   // what was seen is the lot's balance
        $this->countLot($this->tank, $item, $l1, '29', '30')->assertStatus(201);

        $this->assertSame('29.0000', $this->lotBalance($l1, $this->tank));
        $this->assertSame('49.0000', $this->semenRow($rambo)['stocks'][0]['quantity']);
        $this->assertSame([], $this->reconcile());
    }
}
