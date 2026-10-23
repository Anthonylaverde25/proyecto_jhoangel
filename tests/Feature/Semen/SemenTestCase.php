<?php

declare(strict_types=1);

namespace Tests\Feature\Semen;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Warehouses\WarehouseTestCase;

/** Semen stock (semen S-A) over the API: sires, entries, lots and their identification. */
abstract class SemenTestCase extends WarehouseTestCase
{
    protected int $tank;

    protected function setUp(): void
    {
        parent::setUp();
        // The dev seeder may bring tanks and semen: each test starts from its own tank.
        DB::table('warehouses')->where('company_id', $this->company->id)->where('kind', 'CRYO_TANK')->update(['is_default' => false, 'is_active' => false]);
        $this->tank = $this->newWarehouse('TER-' . random_int(100, 999), 'Termo test ' . Str::random(4), 'CRYO_TANK');
    }

    protected function sire(string $name = 'Rambo test', ?string $registry = null): int
    {
        return (int) $this->apiAs('POST', '/semen/sires', ['name' => $name, 'registry_code' => $registry ?? 'REG-' . Str::upper(Str::random(6))])
            ->assertStatus(201)->json('id');
    }

    /** @return array<string, mixed> */
    protected function receiptLine(int $sireId, ?string $lot, int $straws, string $cost = '1000', string $type = 'CONVENTIONAL'): array
    {
        return ['semen_sire_id' => $sireId, 'semen_type' => $type, 'lot_code' => $lot, 'quantity' => (string) $straws, 'unit_cost' => $cost];
    }

    /** @param list<array<string, mixed>> $lines */
    protected function receipt(array $lines, array $overrides = []): TestResponse
    {
        return $this->apiAs('POST', '/semen/receipts', $overrides + [
            'occurred_at' => now()->subDays(5)->toDateString(),
            'warehouse_id' => $this->tank,
            'idempotency_key' => (string) Str::uuid(),
            'lines' => $lines,
        ]);
    }

    /** The semen item of a sire and type, from the inventory. */
    protected function semenRow(int $sireId, string $type = 'CONVENTIONAL'): array
    {
        return collect($this->apiAs('GET', '/semen/inventory?include_inactive=1')->assertOk()->json())
            ->first(fn (array $row) => $row['sire']['id'] === $sireId && $row['semen_type']['value'] === $type) ?? [];
    }

    protected function lotId(int $itemId, string $code): int
    {
        return (int) DB::table('semen_lots')->where('item_id', $itemId)->where('code', $code)->value('id');
    }

    protected function lotBalance(int $lotId, int $warehouseId): string
    {
        return bcadd((string) (DB::table('semen_lot_stocks')->where('semen_lot_id', $lotId)->where('warehouse_id', $warehouseId)->value('quantity') ?? '0'), '0', 4);
    }

    /** @return array<string, mixed> */
    protected function straws(int $itemId, string $quantity, ?int $lotId): array
    {
        return ['item_id' => $itemId, 'input_quantity' => $quantity, 'input_unit_id' => $this->unitId('STRAW'), 'semen_lot_id' => $lotId];
    }

    protected function countLot(int $warehouseId, int $itemId, ?int $lotId, string $counted, string $seen, string $reason = 'Conteo de prueba'): TestResponse
    {
        return $this->apiAs('POST', '/item-documents/counts', [
            'warehouse_id' => $warehouseId, 'item_id' => $itemId, 'semen_lot_id' => $lotId,
            'counted_quantity' => $counted, 'system_quantity_seen' => $seen, 'input_unit_id' => $this->unitId('STRAW'),
            'reason' => $reason, 'occurred_at' => now()->subDay()->toDateString(), 'idempotency_key' => (string) Str::uuid(),
        ]);
    }
}
