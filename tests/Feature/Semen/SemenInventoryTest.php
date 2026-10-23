<?php

declare(strict_types=1);

namespace Tests\Feature\Semen;

/** Pestaña Semen: inventario, partidas por termo y detalle de partida (4.7, 5.7). */
class SemenInventoryTest extends SemenTestCase
{
    public function test_inventory_and_lot_detail(): void
    {
        $rambo = $this->sire('Rambo inventario', 'HBA-INV');
        $doc = $this->receipt([$this->receiptLine($rambo, 'L-1', 12, '500')], ['provider_id' => $this->seller->id])->assertStatus(201)->json();
        $item = $doc['lines'][0]['item']['id'];
        $lot = $this->lotId($item, 'L-1');
        $this->consume([$this->straws($item, '2', $lot)], ['warehouse_id' => $this->tank])->assertStatus(201);

        $row = $this->semenRow($rambo);
        $this->assertSame('HBA-INV', $row['sire']['registry_code']);
        $this->assertSame('10.0000', $row['stocks'][0]['quantity']);
        $this->assertSame('5000.0000', $row['stocks'][0]['value']);
        $this->assertSame('10.0000', $row['lots'][0]['balances'][0]['quantity']);
        $this->assertSame($this->seller->name, $row['lots'][0]['first_entry']['provider']);

        $this->apiAs('GET', "/semen/lots?item_id={$item}&warehouse_id={$this->tank}")->assertOk()->assertJsonPath('0.quantity', '10.0000');
        $detail = $this->apiAs('GET', "/semen/lots/{$lot}")->assertOk()->json();
        $this->assertSame(['SEMEN_RECEIPT', 'CONSUMPTION'], array_column(array_column($detail['movements'], 'document'), 'type'));

        // Filters: a tank without it, and with balance only.
        $empty = $this->newWarehouse('TER-' . random_int(100, 999), 'Termo vacío', 'CRYO_TANK');
        $this->assertNotContains($item, collect($this->apiAs('GET', "/semen/inventory?with_balance=1&warehouse_id={$empty}")->json())->pluck('item.id')->all());
        $this->asOther(fn () => $this->apiAs('GET', "/semen/lots/{$lot}")->assertNotFound());
    }
}
