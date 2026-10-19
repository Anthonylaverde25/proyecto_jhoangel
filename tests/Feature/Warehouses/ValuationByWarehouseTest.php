<?php

declare(strict_types=1);

namespace Tests\Feature\Warehouses;

use Illuminate\Support\Facades\DB;

/** Example 2 of the plan's part 2: each lot is valued where it entered (H1, H2), and voiding undoes all (H3). */
class ValuationByWarehouseTest extends WarehouseTestCase
{
    public function test_an_invoice_values_each_lot_in_its_own_warehouse(): void
    {
        [$item] = $this->stockedItem();
        [$oil] = $this->stockedItem('Gasoil test', 'L', 'Tambor x 200', '200');
        $main = $this->defaultWarehouseId();
        $shed = $this->newWarehouse('TST-02', 'Galpón');

        $first = $this->deliveryNote([$this->noteLine($item, '300')], false)->assertStatus(201);
        $fuel = $this->deliveryNote([$this->noteLine($oil, '1000', '900', null, 'L')], true)->assertStatus(201);
        $this->apiAs('PATCH', "/warehouses/{$shed}/default")->assertOk();
        $second = $this->deliveryNote([$this->noteLine($item, '200')], false)->assertStatus(201);

        $fuelLine = $this->line($oil, '1000', '900', null, 'L', ['delivery_note_line_id' => $fuel->json('lines.0.id')]);
        $fuelLine['taxes'][] = ['tax_id' => $this->taxId('FUEL_TAX'), 'amount' => '95000'];
        $invoice = $this->convert([$first->json('id'), $second->json('id'), $fuel->json('id')], [
            $this->fromNote($first->json('lines.0.id'), $item, '300', '40'),
            $this->fromNote($second->json('lines.0.id'), $item, '200', '40'),
            $fuelLine,
        ])->assertStatus(201);

        $valuations = $this->documentsOf($invoice->json('id'), 'VALUATION');
        $this->assertSame([$main, $shed], $valuations->pluck('warehouse_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame(2, DB::table('item_document_lines')->where('item_document_id', $valuations[0]->id)->count(), 'the lot and the fuel tax of ALM-01');

        $a = $this->stockIn($main, $item);
        $b = $this->stockIn($shed, $item);
        $this->assertSame(['300.0000', '0.0000', '40.000000'], [$a['quantity'], $a['unvalued_quantity'], $a['average_unit_cost']]);
        $this->assertSame(['200.0000', '0.0000', '40.000000'], [$b['quantity'], $b['unvalued_quantity'], $b['average_unit_cost']]);
        $this->assertSame('995.000000', $this->stockIn($main, $oil)['average_unit_cost']);

        $this->apiAs('POST', "/fiscal-documents/{$invoice->json('id')}/void", ['reason' => 'Mal cargada'])->assertOk();
        $this->assertSame(2, DB::table('item_documents')->where('fiscal_document_id', $invoice->json('id'))->where('type', 'REVERSAL')->count());
        $this->assertSame('300.0000', $this->stockIn($main, $item)['unvalued_quantity']);
        $this->assertSame('200.0000', $this->stockIn($shed, $item)['unvalued_quantity']);
        $this->assertSame('900.000000', $this->stockIn($main, $oil)['average_unit_cost']);
        $this->assertSame([], $this->reconcile());
    }
}
