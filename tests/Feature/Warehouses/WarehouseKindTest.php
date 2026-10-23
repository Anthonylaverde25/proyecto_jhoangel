<?php

declare(strict_types=1);

namespace Tests\Feature\Warehouses;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Tipos de almacén (semen S-0): predeterminado por tipo, tipo fijo con movimientos y semen sólo en termos. */
class WarehouseKindTest extends WarehouseTestCase
{
    public function test_each_kind_keeps_its_own_default(): void
    {
        $this->clearTanks();
        $main = $this->defaultWarehouseId();

        $this->apiAs('POST', '/warehouses', ['code' => 'ALM-50', 'name' => 'Sin tipo'])->assertStatus(201)
            ->assertJsonPath('kind', 'GENERAL')->assertJsonPath('is_default', false);
        $this->apiAs('POST', '/warehouses', ['code' => 'ALM-51', 'name' => 'Otro', 'kind' => 'FREEZER'])->assertStatus(422)
            ->assertJsonPath('errors.kind.0', 'El tipo es almacén general o termo.');

        // SW4: the first tank becomes the tanks' default; the general default stays.
        $first = $this->apiAs('POST', '/warehouses', ['code' => 'TER-50', 'name' => 'Termo A', 'kind' => 'CRYO_TANK'])->assertStatus(201)
            ->assertJsonPath('kind', 'CRYO_TANK')->assertJsonPath('is_default', true)->json('id');
        $second = $this->newWarehouse('TER-51', 'Termo B', 'CRYO_TANK');
        $list = collect($this->apiAs('GET', '/warehouses')->assertOk()->json());
        $this->assertSame([$main, $first], $list->where('is_default', true)->pluck('id')->values()->all());
        $this->assertSame('GENERAL', $list->first()['kind'], 'general ones first');

        // Swapping the tanks' default leaves the general one alone.
        $list = collect($this->apiAs('PATCH', "/warehouses/{$second}/default")->assertOk()->json());
        $this->assertSame([$main, $second], $list->where('is_default', true)->pluck('id')->values()->all());

        // The default tank leaves naming another tank, never a general warehouse.
        $this->apiAs('PATCH', "/warehouses/{$second}/active", ['is_active' => false, 'new_default_id' => $main])->assertStatus(422)
            ->assertJsonPath('errors.new_default_id.0', 'El nuevo predeterminado tiene que ser otro termo.');
        $this->apiAs('PATCH', "/warehouses/{$second}/active", ['is_active' => false])->assertStatus(422);
        $this->apiAs('PATCH', "/warehouses/{$second}/active", ['is_active' => false, 'new_default_id' => $first])->assertOk();

        // The last active tank may leave alone: the tanks stay without a default until one is activated.
        $list = collect($this->apiAs('PATCH', "/warehouses/{$first}/active", ['is_active' => false])->assertOk()->json());
        $this->assertSame([$main], $list->where('is_default', true)->pluck('id')->values()->all());
        $list = collect($this->apiAs('PATCH', "/warehouses/{$second}/active", ['is_active' => true])->assertOk()->json());
        $this->assertTrue($list->firstWhere('id', $second)['is_default']);
    }

    public function test_the_kind_changes_only_without_movements(): void
    {
        $this->clearTanks();
        $main = $this->defaultWarehouseId();
        [$itemId] = $this->stockedItem();
        $used = $this->newWarehouse('ALM-60', 'Galpón usado');
        $this->initialStockIn($used, $itemId, '100', '2')->assertStatus(201);

        $this->apiAs('PATCH', "/warehouses/{$used}", ['code' => 'ALM-60', 'name' => 'Galpón usado', 'kind' => 'CRYO_TANK'])->assertStatus(422)
            ->assertJsonPath('errors.kind.0', 'El tipo de ALM-60 ya no cambia: tiene movimientos.');
        $this->apiAs('PATCH', "/warehouses/{$main}", ['code' => 'ALM-01', 'name' => 'Almacén principal', 'kind' => 'CRYO_TANK'])->assertStatus(422)
            ->assertJsonPath('errors.kind.0', 'El almacén predeterminado no cambia de tipo: elegí antes otro predeterminado.');

        // An empty general warehouse becomes the first tank, and the tanks' default.
        $empty = $this->newWarehouse('ALM-61', 'Para termo');
        $this->apiAs('PATCH', "/warehouses/{$empty}", ['code' => 'TER-61', 'name' => 'Termo nuevo', 'kind' => 'CRYO_TANK'])->assertOk()
            ->assertJsonPath('kind', 'CRYO_TANK')->assertJsonPath('is_default', true);
        // Back to general while it is the only tank: it simply stops being the tanks' default.
        $this->apiAs('PATCH', "/warehouses/{$empty}", ['code' => 'TER-61', 'name' => 'Termo nuevo', 'kind' => 'GENERAL'])->assertOk()
            ->assertJsonPath('kind', 'GENERAL')->assertJsonPath('is_default', false);
        // Without a kind an edit keeps it.
        $this->apiAs('PATCH', "/warehouses/{$empty}", ['code' => 'TER-61', 'name' => 'Termo renombrado'])->assertOk()->assertJsonPath('kind', 'GENERAL');
    }

    public function test_semen_is_only_kept_in_a_tank(): void
    {
        $this->clearTanks();
        $main = $this->defaultWarehouseId();
        $tank = $this->newWarehouse('TER-70', 'Termo test', 'CRYO_TANK');
        $sire = (int) $this->apiAs('POST', '/semen/sires', ['name' => 'Angus Rambo', 'registry_code' => 'HBA-7070'])->assertStatus(201)->json('id');
        $entry = fn (int $warehouse) => $this->apiAs('POST', '/semen/receipts', [
            'occurred_at' => now()->subDays(5)->toDateString(), 'warehouse_id' => $warehouse, 'idempotency_key' => (string) Str::uuid(),
            'lines' => [['semen_sire_id' => $sire, 'semen_type' => 'CONVENTIONAL', 'lot_code' => 'L-1', 'quantity' => '10', 'unit_cost' => '30']],
        ]);
        [$other] = $this->stockedItem();

        // The semen entry only takes a tank; a tank takes semen and anything else.
        $entry($main)->assertStatus(422)->assertJsonPath('errors.warehouse_id.0', 'ALM-01 · Almacén principal no es un termo: el semen sólo se guarda en termos.');
        $semen = (int) $entry($tank)->assertStatus(201)->json('lines.0.item.id');
        $lot = (int) DB::table('semen_lots')->where('item_id', $semen)->value('id');
        $this->initialStockIn($tank, $other, '100', '2')->assertStatus(201);
        $straws = fn (string $qty) => ['item_id' => $semen, 'input_quantity' => $qty, 'input_unit_id' => $this->unitId('STRAW'), 'semen_lot_id' => $lot];

        // Initial stock never takes semen (SI8); transfer, consumption and count keep it in tanks.
        $this->apiAs('POST', '/item-documents/initial-stock', [
            'warehouse_id' => $tank, 'occurred_at' => now()->subDays(5)->toDateString(), 'idempotency_key' => (string) Str::uuid(),
            'lines' => [['item_id' => $semen, 'input_quantity' => '1', 'input_unit_id' => $this->unitId('STRAW'), 'input_unit_price' => '1']],
        ])->assertStatus(422)
            ->assertJsonPath('errors', ['lines.0.item_id' => ['Semen · Angus Rambo · Convencional es semen: ingresalo desde Artículos y servicios › Semen › Ingresar semen.']]);
        $this->transfer($tank, $main, [$straws('2')])->assertStatus(422)->assertJsonValidationErrors('lines.0.item_id');
        $this->consume([$straws('1')], ['warehouse_id' => $main])->assertStatus(422)->assertJsonValidationErrors('lines.0.item_id');
        $this->consume([$straws('1')], ['warehouse_id' => $tank])->assertStatus(201);

        // A provider document does not take semen yet; other lines of the same paper are judged as usual.
        $this->invoice([$this->line($semen, '5', '30', unit: 'STRAW'), $this->line($other, '10', '2')])->assertStatus(422)
            ->assertJsonPath('errors', ['lines.0.item_id' => ['Semen · Angus Rambo · Convencional es semen: todavía no entra por comprobante. Ingresalo desde Artículos y servicios › Semen › Ingresar semen.']]);
    }

    /** The dev seeder may bring a tank: tests start from a company with none active. */
    private function clearTanks(): void
    {
        DB::table('warehouses')->where('company_id', $this->company->id)->where('kind', 'CRYO_TANK')
            ->update(['is_default' => false, 'is_active' => false]);
    }
}
