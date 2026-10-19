<?php

declare(strict_types=1);

namespace Tests\Feature\Warehouses;

use Illuminate\Support\Facades\DB;

/** Anular una transferencia anula las dos mitades, y no deja negativos en el destino (DD10, DD11). */
class ReverseStockTransferTest extends WarehouseTestCase
{
    public function test_reversing_undoes_both_halves_while_the_destination_still_holds_them(): void
    {
        [$kept] = $this->stockedItem('Ivermectina test');
        [$used] = $this->stockedItem('Vacuna test');
        $main = $this->defaultWarehouseId();
        $shed = $this->newWarehouse('TST-02', 'Galpón');
        foreach ([$kept, $used] as $item) {
            $this->initialStockIn($main, $item, '800', '50')->assertStatus(201);
            $this->initialStockIn($shed, $item, '100', '60')->assertStatus(201);
        }

        $keptOut = $this->transfer($main, $shed, [$this->stockLine($kept, '500')])->assertStatus(201);
        $usedOut = $this->transfer($main, $shed, [$this->stockLine($used, '500')])->assertStatus(201);
        $this->consume([$this->stockLine($kept, '50')], ['warehouse_id' => $shed])->assertStatus(201);
        $this->consume([$this->stockLine($used, '460')], ['warehouse_id' => $shed])->assertStatus(201);

        // Part 2 §2.3: from the entry half; 50 ml stay in TST-02 at $ 68,33 ((550 × 51,666667 − 25.000) / 50 with the stored average).
        $reversal = $this->reverse($keptOut->json('paired.id'))->assertStatus(201)->assertJsonPath('type', 'REVERSAL')
            ->assertJsonPath('reverses.id', $keptOut->json('id'));
        $this->assertSame(2, DB::table('item_documents')->where('type', 'REVERSAL')
            ->whereIn('reverses_document_id', [$keptOut->json('id'), $keptOut->json('paired.id')])->count());
        $this->assertSame(['50.0000', '68.333337'], [$this->stockIn($shed, $kept)['quantity'], $this->stockIn($shed, $kept)['average_unit_cost']]);
        $this->assertSame(['800.0000', '50.000000'], [$this->stockIn($main, $kept)['quantity'], $this->stockIn($main, $kept)['average_unit_cost']]);
        $this->reverse($keptOut->json('id'))->assertStatus(422);
        // The kardex shows the route on the reversals too (E12).
        $reversals = collect($this->apiAs('GET', "/items/{$kept}/kardex")->assertOk()->json('data'))->where('document_type', 'REVERSAL');
        $this->assertSame(['ALM-01 → TST-02', 'ALM-01 → TST-02'], $reversals->pluck('transfer')->values()->all());
        $this->assertNotNull($reversal->json('id'));

        // 460 of the 500 left TST-02: refused, nothing moves.
        $this->reverse($usedOut->json('id'))->assertStatus(422)
            ->assertJsonPath('message', "No se puede anular la transferencia N.º {$usedOut->json('number')}: 360 de los 500 " . $this->stockRowSymbol($used) . ' de Vacuna test ya salieron de TST-02. Anulá primero esos movimientos o transferí de vuelta.');
        $this->assertSame('140.0000', $this->stockIn($shed, $used)['quantity']);

        // DD11: a reversal does not ask whether the warehouse is active.
        $back = $this->transfer($shed, $main, [$this->stockLine($used, '100')])->assertStatus(201);
        $this->apiAs('PATCH', "/warehouses/{$shed}/active", ['is_active' => false])->assertOk();
        $this->reverse($back->json('id'))->assertStatus(201);
        $this->assertSame([], $this->reconcile());
    }

    private function stockRowSymbol(int $itemId): string
    {
        return (string) $this->apiAs('GET', "/items/{$itemId}/stock")->json('item.unit_symbol');
    }
}
