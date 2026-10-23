<?php

declare(strict_types=1);

namespace Tests\Feature\Warehouses;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\DeliveryNotes\DeliveryNoteTestCase;

/**
 * Warehouses and transfers (delivery D) over the API, on top of the stock and delivery-note helpers.
 */
abstract class WarehouseTestCase extends DeliveryNoteTestCase
{
    protected function defaultWarehouseId(): int
    {
        return (int) collect($this->apiAs('GET', '/warehouses')->assertOk()->json())->where('kind', 'GENERAL')->firstWhere('is_default', true)['id'];
    }

    protected function newWarehouse(string $code, string $name, string $kind = 'GENERAL'): int
    {
        return (int) $this->apiAs('POST', '/warehouses', ['code' => $code, 'name' => $name, 'kind' => $kind])->assertStatus(201)->json('id');
    }

    /** Initial stock in ml at a price per ml, into the given warehouse. */
    protected function initialStockIn(int $warehouseId, int $itemId, string $ml, string $pricePerMl): TestResponse
    {
        return $this->apiAs('POST', '/item-documents/initial-stock', [
            'warehouse_id' => $warehouseId,
            'occurred_at' => now()->subDays(10)->toDateString(),
            'idempotency_key' => (string) Str::uuid(),
            'lines' => [['item_id' => $itemId, 'input_quantity' => $ml, 'input_unit_id' => $this->unitId('ML'), 'input_unit_price' => $pricePerMl]],
        ]);
    }

    /** @param list<array<string, mixed>> $lines */
    protected function transfer(int $from, int $to, array $lines, array $overrides = []): TestResponse
    {
        return $this->apiAs('POST', '/item-documents/transfers', $overrides + [
            'from_warehouse_id' => $from,
            'to_warehouse_id' => $to,
            'occurred_at' => now()->subDay()->toDateString(),
            'idempotency_key' => (string) Str::uuid(),
            'lines' => $lines,
        ]);
    }

    protected function reverse(int $documentId, string $reason = 'Cargada por error'): TestResponse
    {
        return $this->apiAs('POST', "/item-documents/{$documentId}/reversal", ['reason' => $reason]);
    }

    /** The item's card in one warehouse, or an empty array when it does not show. */
    protected function stockIn(int $warehouseId, int $itemId): array
    {
        return collect($this->apiAs('GET', "/items/{$itemId}/stock")->assertOk()->json('warehouses'))
            ->firstWhere('warehouse.id', $warehouseId) ?? [];
    }

    /** @return list<array<string, mixed>> */
    protected function reconcile(): array
    {
        return app(\App\Core\Interfaces\IItemStockRepository::class)->reconcile();
    }

    protected function documentsOf(int $fiscalDocumentId, string $type): \Illuminate\Support\Collection
    {
        return DB::table('item_documents')->where('fiscal_document_id', $fiscalDocumentId)->where('type', $type)->orderBy('warehouse_id')->get();
    }
}
