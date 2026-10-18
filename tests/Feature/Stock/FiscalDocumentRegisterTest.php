<?php

declare(strict_types=1);

namespace Tests\Feature\Stock;

use App\Models\FiscalDocument;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Duplicates (DS21), voiding (DS20) and credit notes.
 */
class FiscalDocumentRegisterTest extends StockTestCase
{
    public function test_the_same_number_of_the_same_provider_is_refused_while_registered(): void
    {
        [$item] = $this->stockedItem();
        $first = $this->invoice([$this->line($item, '1', '10')], ['number' => 123])->assertStatus(201)->json('id');

        $this->invoice([$this->line($item, '1', '10')], ['number' => 123])
            ->assertStatus(422)->assertJsonValidationErrors('number');

        // Another provider, another type or another point of sale is another document.
        $this->invoice([$this->line($item, '1', '10')], ['number' => 123, 'point_of_sale' => 2])->assertStatus(201);

        // Voided, it can be loaded again.
        $this->apiAs('POST', "/fiscal-documents/{$first}/void", ['reason' => 'Mal cargada'])->assertOk()->assertJsonPath('status', 'VOIDED');
        $this->invoice([$this->line($item, '1', '10')], ['number' => 123])->assertStatus(201);
    }

    public function test_the_database_refuses_a_registered_duplicate_too(): void
    {
        [$item] = $this->stockedItem();
        $id = $this->invoice([$this->line($item, '1', '10')], ['number' => 77])->assertStatus(201)->json('id');
        $row = (array) DB::table('fiscal_documents')->where('id', $id)->first();
        unset($row['id']);
        $row['idempotency_key'] = 'copy';

        $this->expectException(QueryException::class);
        DB::table('fiscal_documents')->insert($row);
    }

    public function test_tickets_without_number_never_collide(): void
    {
        [$item] = $this->stockedItem();
        $plain = $this->line($item, '1', '10');
        unset($plain['taxes']);
        $ticket = ['fiscal_document_type_id' => $this->typeId('TICKET'), 'point_of_sale' => null, 'number' => null, 'reference' => 'T-1'];

        $this->invoice([$plain], $ticket)->assertStatus(201)->assertJsonPath('formatted_number', 'T-1');
        $this->invoice([$plain], $ticket)->assertStatus(201);
    }

    public function test_voiding_reverses_its_stock_operation_in_the_same_transaction(): void
    {
        [$item, $bottle] = $this->stockedItem();
        $id = $this->invoice([$this->line($item, '2', '12000', $bottle)])->assertStatus(201)->json('id');

        $voided = $this->apiAs('POST', "/fiscal-documents/{$id}/void", ['reason' => 'Factura repetida'])->assertOk();

        $voided->assertJsonPath('void_reason', 'Factura repetida');
        $this->assertSame('0.0000', $this->balance($item)['quantity']);
        $kardex = $this->apiAs('GET', "/items/{$item}/kardex")->assertOk();
        $kardex->assertJsonPath('total', 2)->assertJsonPath('data.0.document_type', 'REVERSAL');

        $this->apiAs('POST', "/fiscal-documents/{$id}/void", ['reason' => 'Otra vez'])->assertStatus(422);
    }

    public function test_a_purchase_receipt_is_not_reversed_on_its_own(): void
    {
        [$item] = $this->stockedItem();
        $operation = $this->invoice([$this->line($item, '1', '10')])->assertStatus(201)->json('stock_operation.id');

        $this->apiAs('POST', "/item-documents/{$operation}/reversal", ['reason' => 'x'])
            ->assertStatus(422)->assertJsonValidationErrors('domain');
    }

    public function test_voiding_is_refused_when_what_remains_would_be_worth_less_than_nothing(): void
    {
        [$item] = $this->stockedItem();
        $expensive = $this->invoice([$this->line($item, '100', '100')])->assertStatus(201)->json('id');
        $this->consume([$this->stockLine($item, '90')])->assertStatus(201);
        $this->invoice([$this->line($item, '200', '1')])->assertStatus(201);

        $this->apiAs('POST', "/fiscal-documents/{$expensive}/void", ['reason' => 'x'])
            ->assertStatus(422)->assertJsonValidationErrors('domain');

        $this->assertSame('REGISTERED', FiscalDocument::withoutGlobalScopes()->whereKey($expensive)->value('status'));
        $this->assertSame('210.0000', $this->balance($item)['quantity']);
    }

    public function test_a_credit_note_returns_the_goods_at_the_average_cost(): void
    {
        [$item, $bottle] = $this->stockedItem();
        $invoice = $this->invoice([$this->line($item, '10', '12000', $bottle)])->assertStatus(201)->json('id');

        $note = $this->invoice([$this->line($item, '1', '15000', $bottle)], [
            'fiscal_document_type_id' => $this->typeId('CREDIT_NOTE_A'),
            'related_fiscal_document_id' => $invoice,
        ])->assertStatus(201);

        $operation = $this->apiAs('GET', '/item-documents/' . $note->json('stock_operation.id'))->assertOk();
        $operation->assertJsonPath('type', 'RETURN_TO_PROVIDER')
            ->assertJsonPath('lines.0.unit_cost', '24.000000')
            ->assertJsonPath('lines.0.movement.direction', -1);
        $this->assertSame('4500.0000', $this->balance($item)['quantity']);
    }

    public function test_a_credit_note_names_an_invoice_of_the_same_provider(): void
    {
        [$item] = $this->stockedItem();
        $other = $this->provider('30-71231233-5');
        $foreignInvoice = $this->invoice([$this->line($item, '1', '10')], ['provider_id' => $other->id])->assertStatus(201)->json('id');

        $this->invoice([$this->line($item, '1', '10')], [
            'fiscal_document_type_id' => $this->typeId('CREDIT_NOTE_A'),
            'related_fiscal_document_id' => $foreignInvoice,
        ])->assertStatus(422)->assertJsonValidationErrors('related_fiscal_document_id');
    }

    public function test_a_delivery_note_is_not_loaded_yet(): void
    {
        [$item] = $this->stockedItem();

        $this->invoice([$this->line($item, '1', '10')], ['fiscal_document_type_id' => $this->typeId('DELIVERY_NOTE')])
            ->assertStatus(422)->assertJsonValidationErrors('fiscal_document_type_id');
    }

    public function test_another_company_does_not_see_the_document(): void
    {
        [$item] = $this->stockedItem();
        $id = $this->invoice([$this->line($item, '1', '10')])->assertStatus(201)->json('id');

        $this->asOther(fn () => $this->apiAs('GET', "/fiscal-documents/{$id}")->assertStatus(404));
        $this->asOther(fn () => $this->apiAs('GET', '/fiscal-documents')->assertOk()->assertJsonPath('total', 0));
    }
}
