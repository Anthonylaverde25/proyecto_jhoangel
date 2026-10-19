<?php

declare(strict_types=1);

namespace Tests\Feature\Warehouses;

use Illuminate\Support\Facades\DB;

/** Transferencia: sólo lo valorizado, sin negativos, al costo del origen (DD7, DD8, Q1, Q2). */
class StockTransferTest extends WarehouseTestCase
{
    /** Example 1 of the plan's part 2. */
    public function test_example_1_moves_valued_units_at_the_origin_average(): void
    {
        [$item] = $this->stockedItem();
        $main = $this->defaultWarehouseId();
        $shed = $this->newWarehouse('TST-02', 'Galpón');
        $this->initialStockIn($main, $item, '800', '50')->assertStatus(201);
        $this->deliveryNote([$this->noteLine($item, '200')], false)->assertStatus(201);
        $this->initialStockIn($shed, $item, '100', '60')->assertStatus(201);

        $refused = $this->transfer($main, $shed, [$this->stockLine($item, '900')])->assertStatus(422);
        $message = $refused->json('errors')['lines.0.input_quantity'][0];
        $this->assertStringStartsWith('Hay 800 ', $message);
        $this->assertStringContainsString('valorizados en ALM-01. Otros 200 ', $message);
        $this->assertStringEndsWith('están pendientes de valorizar y no se transfieren.', $message);

        $out = $this->transfer($main, $shed, [$this->stockLine($item, '500')])->assertStatus(201)
            ->assertJsonPath('type', 'TRANSFER_OUT')
            ->assertJsonPath('warehouse.code', 'ALM-01')
            ->assertJsonPath('lines.0.unit_cost', '50.000000')
            ->assertJsonPath('lines.0.total_cost', '25000.0000')
            ->assertJsonPath('paired.type', 'TRANSFER_IN')
            ->assertJsonPath('paired.warehouse.code', 'TST-02');

        $in = $this->apiAs('GET', '/item-documents/' . $out->json('paired.id'))->assertOk()
            ->assertJsonPath('paired.id', $out->json('id'))
            ->assertJsonPath('lines.0.unit_cost', '50.000000')
            ->assertJsonPath('lines.0.total_cost', '25000.0000');
        $this->assertSame($out->json('number') + 1, $in->json('number'));

        $origin = $this->stockIn($main, $item);
        $this->assertSame(['500.0000', '200.0000', '50.000000', '15000.0000'], [$origin['quantity'], $origin['unvalued_quantity'], $origin['average_unit_cost'], $origin['value']]);
        $destination = $this->stockIn($shed, $item);
        // 600 × 51,666667: the average is kept at 6 decimals (B2), so the value shows 0,0002 over the plan's round figure.
        $this->assertSame(['600.0000', '51.666667', '31000.0002'], [$destination['quantity'], $destination['average_unit_cost'], $destination['value']]);

        // The kardex names both warehouses on either half (E12).
        $kardex = collect($this->apiAs('GET', "/items/{$item}/kardex")->assertOk()->json('data'));
        $this->assertSame(['TST-02' => 'ALM-01 → TST-02', 'ALM-01' => 'ALM-01 → TST-02'],
            $kardex->whereIn('document_type', ['TRANSFER_IN', 'TRANSFER_OUT'])->mapWithKeys(fn ($r) => [$r['warehouse'] === 'Galpón' ? 'TST-02' : 'ALM-01' => $r['transfer']])->all());

        // The lot stays where it entered (DD8).
        $this->assertSame($main, (int) DB::table('stock_unvalued_lots')->where('item_id', $item)->value('warehouse_id'));
        $this->assertSame([], $this->reconcile());

        // The list filters both halves of every transfer (the seeded example adds its own two).
        $types = collect($this->apiAs('GET', "/item-documents?type=TRANSFER&item_id={$item}")->assertOk()->json('data'))->pluck('type')->sort()->values()->all();
        $this->assertSame(['TRANSFER_IN', 'TRANSFER_OUT'], $types);

        // The item list knows what each active warehouse holds (H5).
        $row = collect($this->apiAs('GET', '/items')->assertOk()->json())->firstWhere('id', $item);
        $this->assertCount(2, $row['stock']['by_warehouse']);
    }

    public function test_refusals_and_idempotency(): void
    {
        [$item] = $this->stockedItem();
        [$other] = $this->stockedItem('Otro test');
        $main = $this->defaultWarehouseId();
        $shed = $this->newWarehouse('TST-02', 'Galpón');
        $this->initialStockIn($main, $item, '100', '10')->assertStatus(201);

        $this->transfer($main, $main, [$this->stockLine($item, '10')])->assertStatus(422)->assertJsonValidationErrors('to_warehouse_id');
        $this->transfer($main, $shed, [$this->stockLine($item, '10'), $this->stockLine($item, '5')])->assertStatus(422)->assertJsonValidationErrors('lines.1.item_id');

        $this->transfer($main, $shed, [$this->stockLine($item, '10'), $this->stockLine($other, '5')])->assertStatus(422)
            ->assertJsonPath('errors', ['lines.1.input_quantity' => ['No hay Otro test valorizado en ALM-01 para transferir.']]);

        $this->asOther(function () use ($item, $shed) {
            $own = (int) collect($this->apiAs('GET', '/warehouses')->json())->first()['id'];
            $this->transfer($own, $shed, [$this->stockLine($item, '10')])->assertStatus(422);
        });

        $key = 'transfer-replay';
        $first = $this->transfer($main, $shed, [$this->stockLine($item, '10')], ['idempotency_key' => $key])->assertStatus(201);
        $this->transfer($main, $shed, [$this->stockLine($item, '10')], ['idempotency_key' => $key])->assertStatus(200)->assertJsonPath('id', $first->json('id'));
        $this->assertSame('90.0000', $this->stockIn($main, $item)['quantity']);
    }
}
