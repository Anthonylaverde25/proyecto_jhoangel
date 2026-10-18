<?php

declare(strict_types=1);

namespace Tests\Feature\DeliveryNotes;

use Illuminate\Support\Facades\DB;

/** Voiding delivery notes and their invoices (DC10, Q1, model 2.8). */
class VoidDeliveryFlowTest extends DeliveryNoteTestCase
{
    public function test_an_invoiced_note_is_voided_only_after_its_invoice(): void
    {
        [$item, $flask] = $this->stockedItem();
        $note = $this->deliveryNote([$this->noteLine($item, '1', null, $flask)], false);
        $this->convert([$note->json('id')], [$this->fromNote($note->json('lines.0.id'), $item, '1', '12500', $flask)])->assertStatus(201);

        $this->apiAs('POST', "/fiscal-documents/{$note->json('id')}/void", ['reason' => 'x'])
            ->assertStatus(422)->assertJsonValidationErrors('domain');
    }

    public function test_an_unvalued_note_with_units_used_cannot_be_voided(): void
    {
        [$item, $flask] = $this->stockedItem();
        $note = $this->deliveryNote([$this->noteLine($item, '2', null, $flask)], false);
        $consumption = $this->consume([$this->stockLine($item, '300')])->assertStatus(201);

        $refused = $this->apiAs('POST', "/fiscal-documents/{$note->json('id')}/void", ['reason' => 'x'])->assertStatus(422);
        $this->assertStringContainsString("N.º {$consumption->json('number')}", $refused->json('errors.domain.0'));

        $this->apiAs('POST', "/item-documents/{$consumption->json('id')}/reversal", ['reason' => 'Mal cargado'])->assertStatus(201);
        $this->apiAs('POST', "/fiscal-documents/{$note->json('id')}/void", ['reason' => 'x'])->assertOk()->assertJsonPath('delivery_note_status.code', 'VOIDED');

        $row = $this->stockRow($item);
        $this->assertSame(['0.0000', '0.0000'], [$row['quantity'], $row['unvalued_quantity']]);
        $this->assertSame('VOIDED', DB::table('stock_unvalued_lots')->where('item_id', $item)->value('status'));
    }

    public function test_voiding_the_invoice_puts_everything_back(): void
    {
        [$item, $flask] = $this->stockedItem();
        $note = $this->deliveryNote([$this->noteLine($item, '10', null, $flask)], false);
        $this->consume([$this->stockLine($item, '1500')])->assertStatus(201);
        $invoice = $this->convert([$note->json('id')], [$this->fromNote($note->json('lines.0.id'), $item, '12', '12500', $flask)])
            ->assertStatus(201)->assertJsonPath('discrepancies.open', 1);

        $this->apiAs('POST', "/fiscal-documents/{$invoice->json('id')}/void", ['reason' => 'Mal cargada'])
            ->assertOk()->assertJsonPath('discrepancies.cancelled', 1);

        $row = $this->stockRow($item);
        $this->assertSame(['3500.0000', '3500.0000'], [$row['quantity'], $row['unvalued_quantity']]);
        $lot = DB::table('stock_unvalued_lots')->where('item_id', $item)->first();
        $this->assertSame('PENDING', $lot->status);
        $this->assertNull(DB::table('stock_unvalued_issues')->where('lot_id', $lot->id)->value('unit_cost'));
        $this->apiAs('GET', "/delivery-notes/pending?provider_id={$this->seller->id}")->assertOk()->assertJsonPath('data.0.id', $note->json('id'));
        $this->assertSame([], app(\App\Core\Interfaces\IItemStockRepository::class)->reconcile());

        // Converted again, the same number is free once voided (DS21).
        $this->convert([$note->json('id')], [$this->fromNote($note->json('lines.0.id'), $item, '10', '12500', $flask)], ['number' => $invoice->json('number')])
            ->assertStatus(201);
    }

    public function test_the_invoice_cannot_be_voided_once_its_valued_units_left(): void
    {
        [$item, $flask] = $this->stockedItem();
        $note = $this->deliveryNote([$this->noteLine($item, '2', null, $flask)], false);
        $invoice = $this->convert([$note->json('id')], [$this->fromNote($note->json('lines.0.id'), $item, '2', '12500', $flask)])->assertStatus(201);
        $this->consume([$this->stockLine($item, '600')])->assertStatus(201);

        $this->apiAs('POST', "/fiscal-documents/{$invoice->json('id')}/void", ['reason' => 'x'])
            ->assertStatus(422)->assertJsonValidationErrors('domain');
    }

    public function test_reversing_a_consumption_returns_pending_units_to_their_lot(): void
    {
        [$item, $flask] = $this->stockedItem();
        $this->initialStock($item, '500', '24');
        $note = $this->deliveryNote([$this->noteLine($item, '2', null, $flask)], false);
        $consumption = $this->consume([$this->stockLine($item, '800')])->assertStatus(201);   // 500 valued + 300 pending

        $this->apiAs('POST', "/item-documents/{$consumption->json('id')}/reversal", ['reason' => 'x'])
            ->assertStatus(201)->assertJsonPath('lines.0.unvalued_quantity', '300.0000');

        $row = $this->stockRow($item);
        $this->assertSame(['1500.0000', '1000.0000', '24.000000'], [$row['quantity'], $row['unvalued_quantity'], $row['average_unit_cost']]);
        $this->assertSame('1000.0000', $this->apiAs('GET', "/fiscal-documents/{$note->json('id')}")->json('lines.0.unvalued_lot.remaining_quantity'));
    }

    public function test_reversing_after_the_invoice_returns_the_units_valued(): void
    {
        [$item, $flask] = $this->stockedItem();
        $note = $this->deliveryNote([$this->noteLine($item, '2', null, $flask)], false);
        $consumption = $this->consume([$this->stockLine($item, '400')])->assertStatus(201);
        $this->convert([$note->json('id')], [$this->fromNote($note->json('lines.0.id'), $item, '2', '12500', $flask)])->assertStatus(201);

        $this->apiAs('POST', "/item-documents/{$consumption->json('id')}/reversal", ['reason' => 'x'])->assertStatus(201);

        $row = $this->stockRow($item);
        $this->assertSame(['1000.0000', '0.0000', '25.000000'], [$row['quantity'], $row['unvalued_quantity'], $row['average_unit_cost']]);
        $this->assertSame([], app(\App\Core\Interfaces\IItemStockRepository::class)->reconcile());
    }
}
