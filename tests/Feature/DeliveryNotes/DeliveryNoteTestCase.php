<?php

declare(strict_types=1);

namespace Tests\Feature\DeliveryNotes;

use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Stock\StockTestCase;

/**
 * Delivery notes and their conversion to invoices over the API (delivery C). Ivermectina in ml with
 * a "Frasco x 500" presentation, as in the examples of the plan's part 2.
 */
abstract class DeliveryNoteTestCase extends StockTestCase
{
    /** A delivery-note line: no taxes, priced only when $price is given. */
    protected function noteLine(int $itemId, string $quantity, ?string $price = null, ?int $presentationId = null, string $unit = 'ML'): array
    {
        return [
            'item_id' => $itemId,
            'input_quantity' => $quantity,
            'input_unit_id' => $presentationId === null ? $this->unitId($unit) : null,
            'input_presentation_id' => $presentationId,
            'unit_price' => $price,
        ];
    }

    /** @param list<array<string, mixed>> $lines */
    protected function deliveryNote(array $lines, bool $valued, array $overrides = []): TestResponse
    {
        return $this->apiAs('POST', '/fiscal-documents', $overrides + [
            'provider_id' => $this->seller->id,
            'fiscal_document_type_id' => $this->typeId('DELIVERY_NOTE'),
            'point_of_sale' => 1,
            'number' => random_int(1, 99999999),
            'issued_at' => now()->subDays(5)->toDateString(),
            'is_valued' => $valued,
            'idempotency_key' => (string) Str::uuid(),
            'lines' => $lines,
        ]);
    }

    /**
     * An invoice line converted from a delivery-note line, at 21 % VAT.
     *
     * @return array<string, mixed>
     */
    protected function fromNote(int $deliveryNoteLineId, int $itemId, string $quantity, string $price, ?int $presentationId = null): array
    {
        return $this->line($itemId, $quantity, $price, $presentationId, 'ML', ['delivery_note_line_id' => $deliveryNoteLineId]);
    }

    /**
     * @param list<int> $noteIds
     * @param list<array<string, mixed>> $lines
     */
    protected function convert(array $noteIds, array $lines, array $overrides = []): TestResponse
    {
        return $this->invoice($lines, $overrides + ['delivery_note_ids' => $noteIds, 'issued_at' => now()->subDay()->toDateString()]);
    }

    /** Initial stock in ml at a price per ml. */
    protected function initialStock(int $itemId, string $ml, string $pricePerMl): void
    {
        $this->apiAs('POST', '/item-documents/initial-stock', [
            'occurred_at' => now()->subDays(10)->toDateString(),
            'idempotency_key' => (string) Str::uuid(),
            'lines' => [['item_id' => $itemId, 'input_quantity' => $ml, 'input_unit_id' => $this->unitId('ML'), 'input_unit_price' => $pricePerMl]],
        ])->assertStatus(201);
    }

    /** @return array<string, mixed> the MAIN warehouse row of the item's Stock tab */
    protected function stockRow(int $itemId): array
    {
        return $this->apiAs('GET', "/items/{$itemId}/stock")->assertOk()->json('warehouses.0') ?? [];
    }
}
