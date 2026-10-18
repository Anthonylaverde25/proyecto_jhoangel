<?php

declare(strict_types=1);

namespace Tests\Feature\DeliveryNotes;

use Illuminate\Support\Facades\DB;

/** The invoice values what arrived unpriced (model 2.3–2.5) and the taxes a valued note cannot carry (2.7, Q3). */
class ValuationTest extends DeliveryNoteTestCase
{
    /** Example 1 of the plan's part 2, step by step. */
    public function test_example_1_consumption_before_the_invoice(): void
    {
        [$item, $flask] = $this->stockedItem();
        $batch = $this->ownBatch();
        $this->initialStock($item, '1000', '24');                                 // 2 flasks at $ 12.000

        $note = $this->deliveryNote([$this->noteLine($item, '10', null, $flask)], false)->assertStatus(201);
        $noteLineId = $note->json('lines.0.id');

        // 5 flasks: 2 valued ($ 24.000) and 3 from the lot.
        $consumption = $this->consume([$this->stockLine($item, '2500')], ['batch_id' => $batch->id, 'heads_treated' => 80])
            ->assertStatus(201)
            ->assertJsonPath('lines.0.unit_cost', '24.000000')
            ->assertJsonPath('lines.0.total_cost', '24000.0000')
            ->assertJsonPath('lines.0.unvalued_quantity', '1500.0000');

        $row = $this->stockRow($item);
        $this->assertSame(['3500.0000', '3500.0000', '0.0000'], [$row['quantity'], $row['unvalued_quantity'], $row['valued_quantity']]);

        $history = $this->apiAs('GET', "/batches/{$batch->id}/consumption-history")->assertOk();
        $this->assertTrue($history->json('has_pending_cost'));
        $this->assertSame('24000.0000', $history->json('rows.0.cost'));
        $this->assertSame('1500.0000', $history->json('rows.0.pending_cost_quantity'));

        // Invoice: 10 flasks at $ 12.500 → $ 25 per ml.
        $this->convert([$note->json('id')], [$this->fromNote($noteLineId, $item, '10', '12500', $flask)])
            ->assertStatus(201)
            ->assertJsonPath('lines.0.unit_cost', '25.000000')
            ->assertJsonPath('delivery_notes.0.id', $note->json('id'));

        $row = $this->stockRow($item);
        $this->assertSame(['3500.0000', '0.0000', '25.000000', '87500.0000'], [$row['quantity'], $row['unvalued_quantity'], $row['average_unit_cost'], $row['value']]);

        $history = $this->apiAs('GET', "/batches/{$batch->id}/consumption-history")->assertOk();
        $this->assertFalse($history->json('has_pending_cost'));
        $this->assertSame('61500.0000', $history->json('rows.0.cost'));
        $this->assertSame('0.0000', $history->json('rows.0.pending_cost_quantity'));

        $this->assertSame('VALUED', DB::table('stock_unvalued_lots')->where('delivery_note_line_id', $noteLineId)->value('status'));
        $this->assertSame('VALUATION', DB::table('item_documents')->where('fiscal_document_id', '!=', $note->json('id'))->orderByDesc('id')->value('type'));
        $this->assertSame([], $this->reconcile());
    }

    public function test_a_lot_whose_units_all_left_is_valued_only_in_its_issues(): void
    {
        [$item, $flask] = $this->stockedItem();
        $note = $this->deliveryNote([$this->noteLine($item, '2', null, $flask)], false)->assertStatus(201);
        $this->consume([$this->stockLine($item, '1000')])->assertStatus(201);

        $invoice = $this->convert([$note->json('id')], [$this->fromNote($note->json('lines.0.id'), $item, '2', '12500', $flask)])->assertStatus(201);
        $lot = DB::table('stock_unvalued_lots')->where('item_id', $item)->first();

        $this->assertSame(0, DB::table('item_documents')->where('fiscal_document_id', $invoice->json('id'))->where('type', 'VALUATION')->count(), 'nothing reached the warehouse');
        $this->assertSame(0, bccomp('25000', (string) DB::table('stock_unvalued_issues')->where('lot_id', $lot->id)->value('total_cost'), 4));
        $this->assertSame('VALUED', $lot->status);
    }

    /** Example 3: a valued note of gas oil, its invoice brings the fuel tax. */
    public function test_example_3_non_recoverable_taxes_of_a_valued_note(): void
    {
        [$oil] = $this->stockedItem('Gasoil test', 'L', 'Tambor x 200', '200');
        $note = $this->deliveryNote([$this->noteLine($oil, '1000', '900', null, 'L')], true)->assertStatus(201);
        $this->consume([$this->stockLine($oil, '400', 'L')])->assertStatus(201);

        $line = $this->line($oil, '1000', '900', null, 'L', ['delivery_note_line_id' => $note->json('lines.0.id')]);
        $line['taxes'][] = ['tax_id' => $this->taxId('FUEL_TAX'), 'amount' => '95000'];
        $this->convert([$note->json('id')], [$line])->assertStatus(201)->assertJsonPath('discrepancies.open', 0);

        $row = $this->stockRow($oil);
        $this->assertSame(['600.0000', '1058.333333'], [$row['quantity'], $row['average_unit_cost']]);
    }

    public function test_a_tax_with_no_valued_stock_left_stays_unabsorbed(): void
    {
        [$oil] = $this->stockedItem('Gasoil test', 'L', 'Tambor x 200', '200');
        $note = $this->deliveryNote([$this->noteLine($oil, '100', '900', null, 'L')], true)->assertStatus(201);
        $this->consume([$this->stockLine($oil, '100', 'L')])->assertStatus(201);

        $line = $this->line($oil, '100', '900', null, 'L', ['delivery_note_line_id' => $note->json('lines.0.id')]);
        $line['taxes'][] = ['tax_id' => $this->taxId('FUEL_TAX'), 'amount' => '9500'];
        $this->convert([$note->json('id')], [$line])->assertStatus(201);

        $this->assertSame(0, bccomp('9500', (string) DB::table('item_document_lines')->where('item_id', $oil)->whereNotNull('unabsorbed_cost')->value('unabsorbed_cost'), 4));
        $this->assertSame('900.000000', $this->stockRow($oil)['average_unit_cost']);
    }

    /** @return list<array<string, mixed>> */
    private function reconcile(): array
    {
        return app(\App\Core\Interfaces\IItemStockRepository::class)->reconcile();
    }
}
