<?php

declare(strict_types=1);

namespace Tests\Feature\DeliveryNotes;

/**
 * The register as the assistant reads it: documents of one item, the items of each document and
 * the delivery notes an invoice comes from (and the invoice a note became).
 */
class FiscalRegisterItemsTest extends DeliveryNoteTestCase
{
    public function test_filters_the_documents_that_contain_an_item(): void
    {
        [$ivermectina, $flask] = $this->stockedItem();
        [$gasoil] = $this->stockedItem('Gasoil test', 'L', 'Bidón x 20', '20');
        $first = $this->invoice([$this->line($ivermectina, '1', '1000', $flask)])->assertCreated()->json('id');
        $second = $this->invoice([$this->line($ivermectina, '2', '1000', $flask), $this->line($gasoil, '20', '900', null, 'L')])
            ->assertCreated()->json('id');
        $this->invoice([$this->line($gasoil, '40', '900', null, 'L')])->assertCreated();

        $ids = array_column($this->apiAs('GET', '/fiscal-documents?item_id=' . $ivermectina)->assertOk()->json('data'), 'id');
        sort($ids);

        $this->assertSame([$first, $second], $ids);
        $this->apiAs('GET', '/fiscal-documents?item_id=' . $gasoil)->assertJsonPath('total', 2);
    }

    public function test_each_row_lists_its_distinct_items(): void
    {
        [$ivermectina, $flask] = $this->stockedItem();
        $this->invoice([$this->line($ivermectina, '1', '1000', $flask), $this->line($ivermectina, '3', '1000', $flask)])->assertCreated();

        $this->apiAs('GET', '/fiscal-documents?item_id=' . $ivermectina)
            ->assertJsonCount(1, 'data.0.items')
            ->assertJsonPath('data.0.items.0.id', $ivermectina)
            ->assertJsonPath('data.0.items.0.name', 'Ivermectina test');
    }

    public function test_the_list_tells_which_notes_an_invoice_comes_from_and_back(): void
    {
        [$item, $flask] = $this->stockedItem();
        $note = $this->deliveryNote([$this->noteLine($item, '2', null, $flask)], false)->assertCreated();
        $lineId = (int) $note->json('lines.0.id');
        $invoice = $this->convert([(int) $note->json('id')], [$this->fromNote($lineId, $item, '2', '1000', $flask)])
            ->assertCreated()->json('id');

        $this->apiAs('GET', '/fiscal-documents?kind=INVOICE')
            ->assertJsonPath('data.0.id', $invoice)
            ->assertJsonPath('data.0.delivery_notes.0.id', (int) $note->json('id'));

        $this->apiAs('GET', '/fiscal-documents?kind=DELIVERY_NOTE')
            ->assertJsonPath('data.0.invoiced_by.id', $invoice)
            ->assertJsonPath('data.0.delivery_note_status.code', 'INVOICED');
    }
}
