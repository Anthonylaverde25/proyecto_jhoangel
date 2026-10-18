<?php

declare(strict_types=1);

namespace Tests\Feature\DeliveryNotes;

use Illuminate\Support\Facades\DB;

/** Loading a delivery note (plan part 5 §5.3, RM1–RM6) and its receipt into stock (model 2.3). */
class RegisterDeliveryNoteTest extends DeliveryNoteTestCase
{
    public function test_a_valued_delivery_note_enters_at_its_price(): void
    {
        [$item, $flask] = $this->stockedItem();

        $this->deliveryNote([$this->noteLine($item, '10', '12000', $flask)], true)
            ->assertStatus(201)
            ->assertJsonPath('is_valued', true)
            ->assertJsonPath('delivery_note_status.code', 'PENDING_INVOICE')
            ->assertJsonPath('lines_amount', '120000.0000')
            ->assertJsonPath('total_amount', '120000.0000')
            ->assertJsonPath('lines.0.unit_cost', '24.000000');

        $row = $this->stockRow($item);
        $this->assertSame('5000.0000', $row['quantity']);
        $this->assertSame('0.0000', $row['unvalued_quantity']);
        $this->assertSame('24.000000', $row['average_unit_cost']);
    }

    public function test_an_unvalued_delivery_note_enters_pending_without_amounts(): void
    {
        [$item, $flask] = $this->stockedItem();
        $this->initialStock($item, '1000', '24');

        $response = $this->deliveryNote([$this->noteLine($item, '10', null, $flask)], false)
            ->assertStatus(201)
            ->assertJsonPath('is_valued', false)
            ->assertJsonPath('lines_amount', null)
            ->assertJsonPath('total_amount', null)
            ->assertJsonPath('lines.0.unit_price', null)
            ->assertJsonPath('lines.0.unvalued_lot.status', 'PENDING')
            ->assertJsonPath('lines.0.unvalued_lot.remaining_quantity', '5000.0000');

        $row = $this->stockRow($item);
        $this->assertSame('6000.0000', $row['quantity']);
        $this->assertSame('5000.0000', $row['unvalued_quantity']);
        $this->assertSame('1000.0000', $row['valued_quantity']);
        $this->assertSame('24.000000', $row['average_unit_cost'], 'pending units do not move the average');
        $this->assertSame('24000.0000', $row['value']);

        $operation = $response->json('stock_operation.id');
        $this->apiAs('GET', "/item-documents/{$operation}")->assertOk()
            ->assertJsonPath('type', 'DELIVERY_RECEIPT')
            ->assertJsonPath('lines.0.unit_cost', null)
            ->assertJsonPath('lines.0.unvalued_quantity', '5000.0000');
        $this->assertSame(1, DB::table('stock_unvalued_lots')->where('item_id', $item)->count());
    }

    public function test_the_rules_of_a_delivery_note_answer_by_field(): void
    {
        [$item, $flask] = $this->stockedItem();
        $visit = $this->serviceItem();

        $this->deliveryNote([$this->noteLine($item, '1', null, $flask)], true, ['is_valued' => null])
            ->assertStatus(422)->assertJsonValidationErrors('is_valued');

        $this->deliveryNote([$this->noteLine($item, '1', null, $flask)], true)
            ->assertStatus(422)->assertJsonValidationErrors('lines.0.unit_price');

        $this->deliveryNote([$this->noteLine($item, '1', '100', $flask)], false)
            ->assertStatus(422)->assertJsonValidationErrors('lines.0.unit_price');

        $this->deliveryNote([$this->noteLine($visit, '1', null, null, 'SERVICE')], false)
            ->assertStatus(422)->assertJsonValidationErrors('lines.0.item_id');

        $taxed = $this->noteLine($item, '1', '100', $flask) + ['taxes' => [['tax_id' => $this->taxId('VAT'), 'tax_rate_id' => $this->rateId('VAT_21')]]];
        $this->deliveryNote([$taxed], true)->assertStatus(422)->assertJsonValidationErrors('lines.0.taxes');

        $this->deliveryNote([$this->noteLine($item, '1', null, $flask)], false, ['number' => null])
            ->assertStatus(422)->assertJsonValidationErrors('number');
    }

    public function test_the_same_delivery_note_is_not_loaded_twice(): void
    {
        [$item, $flask] = $this->stockedItem();
        $this->deliveryNote([$this->noteLine($item, '1', null, $flask)], false, ['number' => 123])->assertStatus(201);

        $this->deliveryNote([$this->noteLine($item, '1', null, $flask)], false, ['number' => 123])
            ->assertStatus(422)->assertJsonValidationErrors('number');

        // An invoice with the same number is another document.
        $this->invoice([$this->line($item, '1', '10')], ['number' => 123])->assertStatus(201);
    }
}
