<?php

declare(strict_types=1);

namespace Tests\Feature\Stock;

use App\Models\Batch;
use App\Models\BatchType;
use App\Models\FiscalDocumentType;
use App\Models\Provider;
use App\Models\Tax;
use App\Models\TaxRate;
use Illuminate\Testing\TestResponse;
use Illuminate\Support\Str;
use Tests\Feature\ItemCatalog\ItemCatalogTestCase;

/**
 * Provider documents and stock operations over the API, with their own items, provider and batch:
 * tests never depend on the seeded examples.
 */
abstract class StockTestCase extends ItemCatalogTestCase
{
    protected Provider $seller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seller = $this->provider('30-7' . random_int(1000000, 9999999) . '-1');
    }

    protected function typeId(string $code): int
    {
        return (int) FiscalDocumentType::query()->where('code', $code)->value('id');
    }

    protected function taxId(string $code): int
    {
        return (int) Tax::query()->where('code', $code)->value('id');
    }

    protected function rateId(string $code): int
    {
        return (int) TaxRate::query()->where('code', $code)->value('id');
    }

    /** A stocked article in ml with a "Frasco x 500 ml" presentation; returns [itemId, presentationId]. */
    protected function stockedItem(string $name = 'Ivermectina test', string $unit = 'ML', string $presentation = 'Frasco x 500', string $factor = '500'): array
    {
        $itemId = $this->createItem(['name' => $name, 'unit_of_measure_id' => $this->unitId($unit)]);
        $presentationId = (int) $this->apiAs('POST', "/items/{$itemId}/presentations", ['name' => $presentation, 'factor_to_item_unit' => $factor])
            ->assertStatus(201)->json('id');

        return [$itemId, $presentationId];
    }

    protected function serviceItem(string $name = 'Visita test'): int
    {
        return $this->createItem(['name' => $name, 'behavior' => 'SERVICE', 'unit_of_measure_id' => $this->unitId('SERVICE')]);
    }

    protected function ownBatch(): Batch
    {
        $operational = BatchType::query()->where('code', 'OPERATIONAL')->value('id');

        return Batch::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'name' => 'Lote stock ' . Str::random(5),
            'farm_id' => null,
            'is_active' => true,
            'batch_type_id' => $operational,
        ]);
    }

    /** A line of letter A at 21 % VAT, in the item's own unit unless a presentation is given. */
    protected function line(int $itemId, string $quantity, string $price, ?int $presentationId = null, string $unit = 'ML', array $extra = []): array
    {
        return $extra + [
            'item_id' => $itemId,
            'input_quantity' => $quantity,
            'input_unit_id' => $presentationId === null ? $this->unitId($unit) : null,
            'input_presentation_id' => $presentationId,
            'unit_price' => $price,
            'taxes' => [['tax_id' => $this->taxId('VAT'), 'tax_rate_id' => $this->rateId('VAT_21')]],
        ];
    }

    /** @param list<array<string, mixed>> $lines */
    protected function invoice(array $lines, array $overrides = []): TestResponse
    {
        return $this->apiAs('POST', '/fiscal-documents', $overrides + [
            'provider_id' => $this->seller->id,
            'fiscal_document_type_id' => $this->typeId('INVOICE_A'),
            'point_of_sale' => 1,
            'number' => random_int(1, 99999999),
            'issued_at' => now()->subDays(3)->toDateString(),
            'idempotency_key' => (string) Str::uuid(),
            'lines' => $lines,
        ]);
    }

    /** @param list<array<string, mixed>> $lines */
    protected function consume(array $lines, array $overrides = []): TestResponse
    {
        return $this->apiAs('POST', '/item-documents/consumptions', $overrides + [
            'occurred_at' => now()->subDay()->toDateString(),
            'idempotency_key' => (string) Str::uuid(),
            'lines' => $lines,
        ]);
    }

    protected function stockLine(int $itemId, string $quantity, string $unit = 'ML'): array
    {
        return ['item_id' => $itemId, 'input_quantity' => $quantity, 'input_unit_id' => $this->unitId($unit)];
    }

    /** @return array{quantity: string, average_unit_cost: string} of the MAIN warehouse */
    protected function balance(int $itemId): array
    {
        $row = $this->apiAs('GET', "/items/{$itemId}/stock")->assertOk()->json('warehouses.0');

        return ['quantity' => $row['quantity'] ?? '0.0000', 'average_unit_cost' => $row['average_unit_cost'] ?? '0.000000'];
    }
}
