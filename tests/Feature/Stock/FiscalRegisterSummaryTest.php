<?php

declare(strict_types=1);

namespace Tests\Feature\Stock;

use Illuminate\Support\Str;

/** The register's counts per filter and the period total (redesign of /fiscal-documents). */
class FiscalRegisterSummaryTest extends StockTestCase
{
    public function test_counts_per_kind_and_status_and_signed_totals_without_delivery_notes(): void
    {
        [$item] = $this->stockedItem();
        $invoice = $this->invoice([$this->line($item, '10', '100')])->assertStatus(201);           // 1.000 + 210
        $this->invoice([$this->line($item, '5', '100')])->assertStatus(201);                      // 500 + 105
        $this->invoice([$this->line($item, '2', '100')], [
            'fiscal_document_type_id' => $this->typeId('CREDIT_NOTE_A'),
            'related_fiscal_document_id' => $invoice->json('id'),
        ])->assertStatus(201);                                                                    // −200 − 42
        $this->apiAs('POST', '/fiscal-documents', [
            'provider_id' => $this->seller->id, 'fiscal_document_type_id' => $this->typeId('DELIVERY_NOTE'),
            'point_of_sale' => 1, 'number' => 9001, 'issued_at' => now()->subDays(2)->toDateString(), 'is_valued' => true,
            'idempotency_key' => (string) Str::uuid(),
            'lines' => [['item_id' => $item, 'input_quantity' => '3', 'input_unit_id' => $this->unitId('ML'), 'unit_price' => '100']],
        ])->assertStatus(201);

        $all = $this->apiAs('GET', "/fiscal-documents?provider_id={$this->seller->id}")->assertOk();
        $this->assertEquals(['INVOICE' => 2, 'CREDIT_NOTE' => 1, 'DELIVERY_NOTE' => 1], $all->json('summary.by_kind'));
        $this->assertSame(['REGISTERED' => 4], $all->json('summary.by_status'));
        $this->assertSame(['count' => 3, 'net' => '1300.0000', 'taxes' => '273.0000', 'total' => '1573.0000'], $all->json('summary.totals'));

        $notes = $this->apiAs('GET', "/fiscal-documents?provider_id={$this->seller->id}&kind=DELIVERY_NOTE")->assertOk();
        $this->assertSame(1, $notes->json('summary.totals.count'));
        $this->assertSame('300.0000', $notes->json('summary.totals.net'));

        $byCuit = $this->apiAs('GET', '/fiscal-documents?search=' . urlencode(substr($this->seller->cuit, 0, 8)))->assertOk();
        $this->assertSame(3, $byCuit->json('summary.totals.count'), 'the search matches the provider CUIT');

        $detail = $this->apiAs('GET', '/fiscal-documents/' . $invoice->json('id'))->assertOk();
        $this->assertSame($this->seller->cuit, $detail->json('provider.cuit'));
        $this->assertNotNull($detail->json('lines.0.item.code'));
    }
}
