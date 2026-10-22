<?php

declare(strict_types=1);

namespace Tests\Feature\Warehouses;

/** What one warehouse holds, as the assistant's warehouse drawer reads it. */
class WarehouseStockTest extends WarehouseTestCase
{
    public function test_lists_the_items_with_balance_of_one_warehouse_only(): void
    {
        [$item] = $this->stockedItem();
        $main = $this->defaultWarehouseId();
        $shed = $this->newWarehouse('TST-02', 'Galpón');
        $this->initialStockIn($shed, $item, '100', '60')->assertStatus(201);

        $response = $this->apiAs('GET', "/warehouses/{$shed}/stock")->assertOk()
            ->assertJsonPath('warehouse.code', 'TST-02')
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.item_id', $item)
            ->assertJsonPath('items.0.quantity', '100.0000')
            ->assertJsonPath('items.0.value', '6000.0000')
            ->assertJsonPath('items.0.is_negative', false);
        $this->assertNotEmpty($response->json('items.0.name'));

        // The kardex names the warehouse by id too, for the assistant's links.
        $this->apiAs('GET', "/items/{$item}/kardex")->assertOk()->assertJsonPath('data.0.warehouse_id', $shed);

        $mainItems = collect($this->apiAs('GET', "/warehouses/{$main}/stock")->assertOk()->json('items'));
        $this->assertNull($mainItems->firstWhere('item_id', $item));
    }

    public function test_another_company_cannot_read_the_warehouse(): void
    {
        $shed = $this->newWarehouse('TST-02', 'Galpón');

        $this->asOther(fn () => $this->apiAs('GET', "/warehouses/{$shed}/stock")->assertStatus(404));
    }
}
