<?php

declare(strict_types=1);

namespace Tests\Feature\DeliveryNotes;

/** Every difference between notes and invoice is a discrepancy (DC7), settled by hand (DC8). */
class DiscrepancyTest extends DeliveryNoteTestCase
{
    public function test_quantity_price_total_and_missing_line(): void
    {
        [$item, $flask] = $this->stockedItem();
        [$salt, $bag] = $this->stockedItem('Sal test', 'KG', 'Bolsa x 25', '25');
        $note = $this->deliveryNote([
            $this->noteLine($item, '10', '12000', $flask),
            $this->noteLine($salt, '2', '7000', $bag),
        ], true);

        $invoice = $this->convert([$note->json('id')], [
            $this->fromNote($note->json('lines.0.id'), $item, '12', '12500', $flask),
        ])->assertStatus(201)->assertJsonPath('discrepancies.open', 4);

        $rows = $this->apiAs('GET', "/fiscal-discrepancies?invoice_id={$invoice->json('id')}")->assertOk()->json();
        $types = array_column($rows, 'type');
        sort($types);
        $this->assertSame(['MISSING_LINE', 'PRICE', 'QUANTITY', 'TOTAL'], $types);

        $price = $rows[array_search('PRICE', array_column($rows, 'type'), true)];
        $this->assertSame('24.000000', $price['expected_value']);
        $this->assertSame('25.000000', $price['actual_value']);
        $this->assertStringContainsString('$ 24 por ml', $price['detail']);
    }

    public function test_one_cent_is_a_difference(): void
    {
        [$item, $flask] = $this->stockedItem();
        $note = $this->deliveryNote([$this->noteLine($item, '1', '12000', $flask)], true);

        $this->convert([$note->json('id')], [$this->fromNote($note->json('lines.0.id'), $item, '1', '12000.01', $flask)])
            ->assertStatus(201)->assertJsonPath('discrepancies.open', 2);   // PRICE and TOTAL
    }

    public function test_against_an_invoice_b_only_quantities_are_compared(): void
    {
        [$item, $flask] = $this->stockedItem();
        $note = $this->deliveryNote([$this->noteLine($item, '10', '12000', $flask)], true);
        $line = $this->fromNote($note->json('lines.0.id'), $item, '10', '14520', $flask);
        unset($line['taxes']);

        $this->convert([$note->json('id')], [$line], ['fiscal_document_type_id' => $this->typeId('INVOICE_B')])
            ->assertStatus(201)->assertJsonPath('discrepancies.open', 0);
    }

    public function test_an_unvalued_note_has_no_price_to_compare(): void
    {
        [$item, $flask] = $this->stockedItem();
        $note = $this->deliveryNote([$this->noteLine($item, '10', null, $flask)], false);

        $this->convert([$note->json('id')], [$this->fromNote($note->json('lines.0.id'), $item, '10', '99999', $flask)])
            ->assertStatus(201)->assertJsonPath('discrepancies.open', 0);
    }

    public function test_resolving_needs_a_note_and_changes_nothing_in_stock(): void
    {
        [$item, $flask] = $this->stockedItem();
        $note = $this->deliveryNote([$this->noteLine($item, '1', '12000', $flask)], true);
        $invoice = $this->convert([$note->json('id')], [$this->fromNote($note->json('lines.0.id'), $item, '1', '13000', $flask)]);
        $id = $this->apiAs('GET', "/fiscal-discrepancies?invoice_id={$invoice->json('id')}&status=OPEN")->json('0.id');
        $average = $this->stockRow($item)['average_unit_cost'];

        $this->apiAs('PATCH', "/fiscal-discrepancies/{$id}/resolve", ['resolution' => ''])
            ->assertStatus(422)->assertJsonValidationErrors('resolution');
        $this->apiAs('PATCH', "/fiscal-discrepancies/{$id}/resolve", ['resolution' => 'El proveedor manda nota de crédito'])
            ->assertOk()->assertJsonPath('status', 'RESOLVED')->assertJsonPath('resolution', 'El proveedor manda nota de crédito');
        $this->apiAs('PATCH', "/fiscal-discrepancies/{$id}/resolve", ['resolution' => 'Otra vez'])->assertStatus(422);

        $this->assertSame($average, $this->stockRow($item)['average_unit_cost'], 'DC8: the stock stays at the note price');
        $this->asOther(fn () => $this->apiAs('PATCH', "/fiscal-discrepancies/{$id}/resolve", ['resolution' => 'x'])->assertStatus(404));
    }
}
