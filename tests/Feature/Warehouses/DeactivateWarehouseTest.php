<?php

declare(strict_types=1);

namespace Tests\Feature\Warehouses;

use Illuminate\Support\Str;

/** Desactivar no se bloquea por el contenido (DD4); un inactivo no recibe cargas nuevas (R4) pero se cuenta (DD9). */
class DeactivateWarehouseTest extends WarehouseTestCase
{
    public function test_deactivating_shows_what_it_holds_and_closes_it_to_new_loads(): void
    {
        [$item] = $this->stockedItem();
        $main = $this->defaultWarehouseId();
        $shed = $this->newWarehouse('TST-02', 'Galpón');
        $this->initialStockIn($shed, $item, '300', '10')->assertStatus(201);
        $before = collect($this->apiAs('GET', '/warehouses')->json())->firstWhere('id', $main)['summary'];
        $this->deliveryNote([$this->noteLine($item, '50')], false)->assertStatus(201); // pending, into ALM-01

        $list = collect($this->apiAs('PATCH', "/warehouses/{$shed}/active", ['is_active' => false])->assertOk()->json());
        $summary = $list->firstWhere('id', $shed)['summary'];
        $this->assertSame([1, '3000.0000', 0, 0, 0], [$summary['items_with_balance'], $summary['valued_value'], $summary['items_with_unvalued'], $summary['pending_lots'], $summary['negative_items']]);
        $mainSummary = $list->firstWhere('id', $main)['summary'];
        // The tenant carries seeded examples: compare with what ALM-01 held before the note.
        $this->assertSame([$before['items_with_unvalued'] + 1, $before['pending_lots'] + 1], [$mainSummary['items_with_unvalued'], $mainSummary['pending_lots']]);

        // R4: no new loads into an inactive warehouse.
        $this->initialStockIn($shed, $item, '10', '10')->assertStatus(422)->assertJsonPath('errors.warehouse_id.0', 'El almacén TST-02 está inactivo.');
        $this->consume([$this->stockLine($item, '10')], ['warehouse_id' => $shed])->assertStatus(422)->assertJsonValidationErrors('warehouse_id');
        $this->transfer($main, $shed, [$this->stockLine($item, '10')])->assertStatus(422)->assertJsonValidationErrors('to_warehouse_id');
        $this->transfer($shed, $main, [$this->stockLine($item, '10')])->assertStatus(422)->assertJsonValidationErrors('from_warehouse_id');

        // DD9: a count still records what is there.
        $this->apiAs('POST', '/item-documents/counts', [
            'item_id' => $item, 'warehouse_id' => $shed, 'system_quantity_seen' => '300', 'counted_quantity' => '280',
            'input_unit_id' => $this->unitId('ML'), 'reason' => 'Faltante', 'occurred_at' => now()->toDateString(), 'idempotency_key' => (string) Str::uuid(),
        ])->assertStatus(201);

        // Still on the item's card while it holds something.
        $card = $this->stockIn($shed, $item);
        $this->assertSame(['280.0000', false, 'TST-02'], [$card['quantity'], $card['warehouse']['is_active'], $card['warehouse']['code']]);

        $this->apiAs('PATCH', "/warehouses/{$shed}/active", ['is_active' => true])->assertOk();
        $this->transfer($shed, $main, [$this->stockLine($item, '280')])->assertStatus(201);

        // Inactive and empty shows while its count notice is open; once resolved, it leaves the card.
        $this->apiAs('PATCH', "/warehouses/{$shed}/active", ['is_active' => false])->assertOk();
        $notice = collect($this->apiAs('GET', "/items/{$item}/stock")->json('open_incidents'))->firstWhere('type', 'COUNT_DISCREPANCY');
        $this->assertSame('0.0000', $this->stockIn($shed, $item)['quantity']);
        $this->apiAs('PATCH', "/stock-incidents/{$notice['id']}/resolve", ['resolution' => 'Se perdieron en el traslado'])->assertOk();
        $this->assertSame([], $this->stockIn($shed, $item));
        $this->assertSame([], $this->reconcile());
    }
}
