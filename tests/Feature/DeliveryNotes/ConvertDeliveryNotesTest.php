<?php

declare(strict_types=1);

namespace Tests\Feature\DeliveryNotes;

use Illuminate\Support\Facades\DB;

/** "Convertir a factura" (DC5, DC6, DC16, RF1–RF4), the pending tray (DC12) and the direct-invoice notice (DC9). */
class ConvertDeliveryNotesTest extends DeliveryNoteTestCase
{
    public function test_the_draft_inherits_from_the_notes_and_saves_nothing(): void
    {
        [$item, $flask] = $this->stockedItem();
        $a = $this->deliveryNote([$this->noteLine($item, '10', null, $flask)], false)->json('id');
        $b = $this->deliveryNote([$this->noteLine($item, '4', '12000', $flask)], true)->json('id');
        $before = DB::table('fiscal_documents')->count();

        $this->apiAs('GET', '/delivery-notes/invoice-draft?' . http_build_query(['ids' => [$a, $b]]))
            ->assertOk()
            ->assertJsonPath('provider.id', $this->seller->id)
            ->assertJsonPath('delivery_notes.0.is_valued', false)
            ->assertJsonPath('delivery_notes.0.lines.0.unit_price', null)
            ->assertJsonPath('delivery_notes.1.lines.0.unit_price', '12000.000000')
            ->assertJsonPath('delivery_notes.1.lines.0.input_presentation_id', $flask);

        $this->assertSame($before, DB::table('fiscal_documents')->count());
    }

    public function test_the_draft_refuses_notes_of_two_providers(): void
    {
        [$item, $flask] = $this->stockedItem();
        $a = $this->deliveryNote([$this->noteLine($item, '1', null, $flask)], false)->json('id');
        $other = $this->provider('30-7' . random_int(1000000, 9999999) . '-2');
        $b = $this->deliveryNote([$this->noteLine($item, '1', null, $flask)], false, ['provider_id' => $other->id])->json('id');

        $this->apiAs('GET', '/delivery-notes/invoice-draft?' . http_build_query(['ids' => [$a, $b]]))
            ->assertStatus(422)->assertJsonValidationErrors('ids');
    }

    public function test_two_notes_go_into_one_invoice_that_moves_no_quantities(): void
    {
        [$item, $flask] = $this->stockedItem();
        $visit = $this->serviceItem();
        $batch = $this->ownBatch();
        $a = $this->deliveryNote([$this->noteLine($item, '10', null, $flask)], false);
        $b = $this->deliveryNote([$this->noteLine($item, '4', '12000', $flask)], true);

        $freight = $this->line($visit, '1', '15000', null, 'SERVICE', ['batch_id' => $batch->id, 'heads_treated' => 10]);
        $invoice = $this->convert([$a->json('id'), $b->json('id')], [
            $this->fromNote($a->json('lines.0.id'), $item, '10', '12500', $flask),
            $this->fromNote($b->json('lines.0.id'), $item, '4', '12000', $flask),
            $freight,
        ])->assertStatus(201)->assertJsonPath('discrepancies.open', 0);

        $this->assertSame('7000.0000', $this->stockRow($item)['quantity'], 'no PURCHASE for what the notes brought in (DC1)');
        $this->apiAs('GET', "/fiscal-documents/{$a->json('id')}")->assertOk()
            ->assertJsonPath('delivery_note_status.code', 'INVOICED')
            ->assertJsonPath('invoiced_by.id', $invoice->json('id'));
        $this->assertSame(0, DB::table('item_documents')->where('fiscal_document_id', $invoice->json('id'))->where('type', 'PURCHASE')->count());
    }

    public function test_a_stocked_article_outside_the_notes_does_not_enter_and_is_an_extra_line(): void
    {
        [$item, $flask] = $this->stockedItem();
        [$wire] = $this->stockedItem('Alambre test', 'UNIT', 'Rollo', '1');
        $note = $this->deliveryNote([$this->noteLine($item, '2', null, $flask)], false);

        $invoice = $this->convert([$note->json('id')], [
            $this->fromNote($note->json('lines.0.id'), $item, '2', '12500', $flask),
            $this->line($wire, '2', '19000', null, 'UNIT'),
        ])->assertStatus(201)->assertJsonPath('discrepancies.open', 1);

        $this->assertSame('0.0000', $this->stockRow($wire)['quantity'] ?? '0.0000');
        $this->assertSame('EXTRA_LINE', DB::table('fiscal_document_discrepancies')->where('invoice_document_id', $invoice->json('id'))->value('type'));
    }

    public function test_a_note_already_invoiced_or_of_another_provider_is_refused(): void
    {
        [$item, $flask] = $this->stockedItem();
        $note = $this->deliveryNote([$this->noteLine($item, '1', null, $flask)], false);
        $line = $this->fromNote($note->json('lines.0.id'), $item, '1', '12500', $flask);
        $this->convert([$note->json('id')], [$line])->assertStatus(201);

        $this->convert([$note->json('id')], [$line])->assertStatus(422)->assertJsonValidationErrors('delivery_note_ids.0');

        $other = $this->provider('30-7' . random_int(1000000, 9999999) . '-3');
        $foreign = $this->deliveryNote([$this->noteLine($item, '1', null, $flask)], false, ['provider_id' => $other->id]);
        $this->convert([$foreign->json('id')], [$this->fromNote($foreign->json('lines.0.id'), $item, '1', '1', $flask)])
            ->assertStatus(422)->assertJsonValidationErrors('delivery_note_ids.0');
    }

    public function test_a_converted_line_keeps_its_item_and_is_used_once(): void
    {
        [$item, $flask] = $this->stockedItem();
        [$other] = $this->stockedItem('Otro test');
        $note = $this->deliveryNote([$this->noteLine($item, '1', null, $flask)], false);
        $id = $note->json('lines.0.id');

        $this->convert([$note->json('id')], [$this->fromNote($id, $other, '1', '1')])
            ->assertStatus(422)->assertJsonValidationErrors('lines.0.item_id');
        $this->convert([$note->json('id')], [$this->fromNote($id, $item, '1', '1', $flask), $this->fromNote($id, $item, '1', '1', $flask)])
            ->assertStatus(422)->assertJsonValidationErrors('lines.1.delivery_note_line_id');
        $ticket = $this->convert([$note->json('id')], [$this->fromNote($id, $item, '1', '1', $flask)], ['fiscal_document_type_id' => $this->typeId('TICKET'), 'point_of_sale' => null, 'number' => null, 'reference' => 'T']);
        $ticket->assertStatus(422)->assertJsonValidationErrors('delivery_note_ids');
    }

    public function test_the_pending_tray_shows_age_and_what_is_unvalued(): void
    {
        [$item, $flask] = $this->stockedItem();
        $note = $this->deliveryNote([$this->noteLine($item, '10', null, $flask)], false, ['issued_at' => now()->subDays(8)->toDateString()]);
        $this->consume([$this->stockLine($item, '1500')])->assertStatus(201);

        // The tenant is born with the seeded notes: this provider's tray holds only this one.
        $this->apiAs('GET', "/delivery-notes/pending?provider_id={$this->seller->id}")->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $note->json('id'))
            ->assertJsonPath('data.0.age_days', 8)
            ->assertJsonPath('data.0.is_valued', false)
            ->assertJsonPath('data.0.unvalued_pending', '3500.0000')
            ->assertJsonPath('data.0.unvalued_issued', '1500.0000')
            ->assertJsonPath('data.0.lines_with_issues', 1);

        $other = $this->provider('30-7' . random_int(1000000, 9999999) . '-4');
        $this->apiAs('GET', "/delivery-notes/pending?provider_id={$other->id}")->assertOk()->assertJsonCount(0, 'data');
        $this->asOther(fn () => $this->apiAs('GET', '/delivery-notes/pending')->assertOk()->assertJsonMissing(['id' => $note->json('id')]));
    }

    public function test_a_direct_invoice_still_buys(): void
    {
        [$item] = $this->stockedItem();
        $this->deliveryNote([$this->noteLine($item, '1000', null)], false)->assertStatus(201);

        $this->invoice([$this->line($item, '500', '20')])->assertStatus(201);
        $this->assertSame('1500.0000', $this->stockRow($item)['quantity']);
    }
}
