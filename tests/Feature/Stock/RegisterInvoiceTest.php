<?php

declare(strict_types=1);

namespace Tests\Feature\Stock;

class RegisterInvoiceTest extends StockTestCase
{
    public function test_a_mixed_invoice_moves_only_the_stocked_line_at_its_net_cost(): void
    {
        [$ivermectin, $bottle] = $this->stockedItem();
        $visit = $this->serviceItem();
        $batch = $this->ownBatch();

        $response = $this->invoice([
            $this->line($ivermectin, '10', '12000', $bottle),
            $this->line($visit, '1', '60000', null, 'SERVICE', ['batch_id' => $batch->id, 'heads_treated' => 80]),
        ], ['declared_total' => '217800'])->assertStatus(201);

        $response->assertJsonPath('lines_amount', '180000.0000')
            ->assertJsonPath('line_taxes_amount', '37800.0000')
            ->assertJsonPath('total_amount', '217800.0000')
            ->assertJsonPath('total_difference', '0.0000')
            ->assertJsonPath('lines.0.quantity', '5000.0000')
            ->assertJsonPath('lines.0.unit_cost', '24.000000')
            ->assertJsonPath('lines.0.taxes.0.amount', '25200.0000')
            ->assertJsonPath('tax_summary.0.label', 'IVA 21 %')
            ->assertJsonPath('tax_summary.0.amount', '37800.0000');
        $this->assertNotNull($response->json('stock_operation.id'));

        $operation = $this->apiAs('GET', '/item-documents/' . $response->json('stock_operation.id'))->assertOk();
        $operation->assertJsonPath('type', 'PURCHASE')->assertJsonCount(1, 'lines')
            ->assertJsonPath('lines.0.movement.balance_after', '5000.0000')
            ->assertJsonPath('lines.0.movement.average_cost_after', '24.000000');
        $this->assertSame(['quantity' => '5000.0000', 'average_unit_cost' => '24.000000'], $this->balance($ivermectin));
    }

    public function test_an_invoice_of_services_only_creates_no_stock_operation(): void
    {
        $visit = $this->serviceItem();

        $this->invoice([$this->line($visit, '1', '60000', null, 'SERVICE')])
            ->assertStatus(201)
            ->assertJsonPath('stock_operation', null);
    }

    public function test_a_fuel_tax_on_the_line_is_cost_and_a_perception_on_the_document_is_not(): void
    {
        [$gasoil] = $this->stockedItem('Gasoil test', 'L', 'Bidon x 20', '20');

        $line = $this->line($gasoil, '1000', '900', null, 'L');
        $line['taxes'][] = ['tax_id' => $this->taxId('FUEL_TAX'), 'amount' => '95000'];
        $response = $this->invoice([$line], [
            'declared_total' => '1211000',
            'document_taxes' => [['tax_id' => $this->taxId('IIBB_PERCEPTION_AR_B'), 'rate' => '3', 'taxable_base' => '900000', 'amount' => '27000']],
        ])->assertStatus(201);

        $response->assertJsonPath('lines.0.non_recoverable_tax_amount', '95000.0000')
            ->assertJsonPath('lines.0.cost_amount', '995000.0000')
            ->assertJsonPath('lines.0.unit_cost', '995.000000')
            ->assertJsonPath('document_taxes_amount', '27000.0000')
            ->assertJsonPath('total_amount', '1211000.0000');
        $this->assertSame('995.000000', $this->balance($gasoil)['average_unit_cost']);
    }

    public function test_letter_a_needs_exactly_one_vat_per_line_in_force(): void
    {
        [$item] = $this->stockedItem();
        $noVat = $this->line($item, '1', '10');
        $noVat['taxes'] = [];
        $twice = $this->line($item, '1', '10');
        $twice['taxes'][] = $twice['taxes'][0];

        $this->invoice([$noVat, $twice])->assertStatus(422)
            ->assertJsonValidationErrors(['lines.0.taxes', 'lines.1.taxes.1.tax_id']);
    }

    public function test_letter_b_discriminates_no_tax_and_its_price_is_the_cost(): void
    {
        [$item] = $this->stockedItem();

        $this->invoice([$this->line($item, '2', '100')], ['fiscal_document_type_id' => $this->typeId('INVOICE_B')])
            ->assertStatus(422)->assertJsonValidationErrors('lines.0.taxes');

        $plain = $this->line($item, '2', '121');
        unset($plain['taxes']);
        $this->invoice([$plain], ['fiscal_document_type_id' => $this->typeId('INVOICE_B')])
            ->assertStatus(201)
            ->assertJsonPath('total_amount', '242.0000')
            ->assertJsonPath('lines.0.unit_cost', '121.000000');
    }

    public function test_a_perception_on_a_line_or_vat_on_the_document_is_refused(): void
    {
        [$item] = $this->stockedItem();
        $line = $this->line($item, '1', '10');
        $line['taxes'][] = ['tax_id' => $this->taxId('VAT_PERCEPTION'), 'amount' => '3'];

        $this->invoice([$line], ['document_taxes' => [['tax_id' => $this->taxId('FUEL_TAX'), 'amount' => '5']]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['lines.0.taxes.1.tax_id', 'document_taxes.0.tax_id']);
    }

    public function test_every_broken_line_is_reported_at_once(): void
    {
        [$item] = $this->stockedItem();
        $dose = $this->createItem(['name' => 'Vacuna test', 'unit_of_measure_id' => $this->unitId('DOSE')]);
        $batch = $this->ownBatch();

        $this->invoice([
            $this->line($item, '1', '10', null, 'ML', ['batch_id' => $batch->id, 'heads_treated' => 10]),
            $this->line($dose, '5', '10', null, 'L'),
        ])->assertStatus(422)->assertJsonValidationErrors(['lines.0.batch_id', 'lines.1.input_unit_id']);
    }

    public function test_the_provider_must_sell_items_and_be_of_the_company(): void
    {
        [$item] = $this->stockedItem();
        $livestock = $this->provider('30-71231231-9', false);
        $foreign = $this->provider('30-71231232-7', true, $this->other);

        $this->invoice([$this->line($item, '1', '1')], ['provider_id' => $livestock->id])
            ->assertStatus(422)->assertJsonValidationErrors('provider_id');
        $this->invoice([$this->line($item, '1', '1')], ['provider_id' => $foreign->id])
            ->assertStatus(422)->assertJsonValidationErrors('provider_id');
    }

    public function test_a_declared_total_that_differs_is_saved_with_its_difference(): void
    {
        [$item] = $this->stockedItem();

        $this->invoice([$this->line($item, '1', '100')], ['declared_total' => '123.40'])
            ->assertStatus(201)->assertJsonPath('total_difference', '2.4000');
    }

    public function test_the_same_idempotency_key_returns_the_same_document(): void
    {
        [$item] = $this->stockedItem();
        $payload = ['idempotency_key' => 'retry-1', 'number' => 555];

        $first = $this->invoice([$this->line($item, '1', '100')], $payload)->assertStatus(201)->json('id');
        $second = $this->invoice([$this->line($item, '1', '100')], $payload)->assertStatus(200)->json('id');

        $this->assertSame($first, $second);
        $this->assertSame('1.0000', $this->balance($item)['quantity']);
    }
}
