<?php

declare(strict_types=1);

namespace Tests\Feature\Warehouses;

/** Internal operations declare their warehouse; without one, the default (DD6). Provider papers never do (DD5). */
class WarehouseSelectionTest extends WarehouseTestCase
{
    public function test_operations_go_to_the_declared_warehouse_or_the_default(): void
    {
        [$item] = $this->stockedItem();
        $main = $this->defaultWarehouseId();
        $shed = $this->newWarehouse('TST-02', 'Galpón');

        $this->initialStock($item, '100', '10');                                           // no warehouse: default
        $this->initialStockIn($shed, $item, '40', '10')->assertStatus(201)->assertJsonPath('warehouse.id', $shed);
        $this->consume([$this->stockLine($item, '15')], ['warehouse_id' => $shed])->assertStatus(201)->assertJsonPath('warehouse.code', 'TST-02');
        $this->consume([$this->stockLine($item, '5')])->assertStatus(201)->assertJsonPath('warehouse.id', $main);
        $this->assertSame(['95.0000', '25.0000'], [$this->stockIn($main, $item)['quantity'], $this->stockIn($shed, $item)['quantity']]);

        // An invoice enters the default, whichever it is now.
        $this->apiAs('PATCH', "/warehouses/{$shed}/default")->assertOk();
        $invoice = $this->invoice([$this->line($item, '10', '12')])->assertStatus(201);
        $this->assertSame($shed, (int) $this->documentsOf($invoice->json('id'), 'PURCHASE')->first()->warehouse_id);

        $foreign = $this->asOther(fn () => (int) collect($this->apiAs('GET', '/warehouses')->json())->first()['id']);
        $this->consume([$this->stockLine($item, '1')], ['warehouse_id' => $foreign])->assertStatus(422)
            ->assertJsonPath('errors.warehouse_id.0', 'El almacén no existe en esta empresa.');
    }
}
