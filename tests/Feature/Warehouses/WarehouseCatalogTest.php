<?php

declare(strict_types=1);

namespace Tests\Feature\Warehouses;

/** Alta, edición, predeterminado y aislamiento por empresa (DD1, DD2, R1). */
class WarehouseCatalogTest extends WarehouseTestCase
{
    public function test_codes_are_proposed_normalised_and_unique_per_company(): void
    {
        $this->apiAs('GET', '/warehouses')->assertOk()->assertJsonPath('0.code', 'ALM-01')->assertJsonPath('0.is_default', true);
        // The tenant carries the seeded examples: the proposal follows whatever codes exist.
        $codes = collect($this->apiAs('GET', '/warehouses')->json())->pluck('code')->all();
        $this->apiAs('GET', '/warehouses/next-code')->assertOk()->assertJsonPath('code', \App\Core\ValueObjects\WarehouseCode::next($codes));

        $this->apiAs('POST', '/warehouses', ['code' => ' alm-77 ', 'name' => '  Galpón   de prueba '])->assertStatus(201)
            ->assertJsonPath('code', 'ALM-77')
            ->assertJsonPath('name', 'Galpón de prueba')
            ->assertJsonPath('is_default', false)
            ->assertJsonPath('is_active', true);
        $this->apiAs('GET', '/warehouses/next-code')->assertOk()->assertJsonPath('code', 'ALM-78');

        $this->apiAs('POST', '/warehouses', ['code' => 'ALM-77', 'name' => 'Galpón de prueba'])->assertStatus(422)
            ->assertJsonPath('errors.code.0', 'Ya hay un almacén con el código ALM-77.')
            ->assertJsonPath('errors.name.0', 'Ya hay un almacén que se llama Galpón de prueba.');
        $this->apiAs('POST', '/warehouses', ['code' => 'ALM 9', 'name' => 'Otro'])->assertStatus(422)->assertJsonValidationErrors('code');

        $id = $this->newWarehouse('TST-03', 'Tanque de gasoil');
        $this->apiAs('PATCH', "/warehouses/{$id}", ['code' => 'tst-03', 'name' => 'Tanque de combustible'])->assertOk()
            ->assertJsonPath('name', 'Tanque de combustible');
        $this->apiAs('PATCH', "/warehouses/{$id}", ['code' => 'ALM-77', 'name' => 'Tanque de combustible'])->assertStatus(422)->assertJsonValidationErrors('code');

        // The other company keeps its own catalog: same code allowed, nothing shared.
        $this->asOther(function () use ($id) {
            $this->assertSame(['ALM-01'], collect($this->apiAs('GET', '/warehouses')->assertOk()->json())->pluck('code')->all());
            $this->apiAs('POST', '/warehouses', ['code' => 'ALM-77', 'name' => 'Galpón de prueba'])->assertStatus(201);
            $this->apiAs('PATCH', "/warehouses/{$id}", ['code' => 'X', 'name' => 'Y'])->assertStatus(404);
        });
    }

    public function test_the_default_changes_atomically_and_is_always_active(): void
    {
        $main = $this->defaultWarehouseId();
        $second = $this->newWarehouse('TST-02', 'Galpón');

        $list = $this->apiAs('PATCH', "/warehouses/{$second}/default")->assertOk()->json();
        $this->assertSame([$second], collect($list)->where('is_default', true)->pluck('id')->all());
        $this->assertSame($second, $list[0]['id'], 'default first');

        // DD3: the default leaves only naming the next one.
        $this->apiAs('PATCH', "/warehouses/{$second}/active", ['is_active' => false])->assertStatus(422)
            ->assertJsonPath('errors.new_default_id.0', 'Elegí qué almacén pasa a ser el predeterminado.');
        $this->apiAs('PATCH', "/warehouses/{$second}/active", ['is_active' => false, 'new_default_id' => $second])->assertStatus(422)
            ->assertJsonValidationErrors('new_default_id');

        $list = collect($this->apiAs('PATCH', "/warehouses/{$second}/active", ['is_active' => false, 'new_default_id' => $main])->assertOk()->json());
        $this->assertTrue($list->firstWhere('id', $main)['is_default']);
        $this->assertFalse($list->firstWhere('id', $second)['is_default']);
        $this->assertFalse($list->firstWhere('id', $second)['is_active']);

        $this->apiAs('PATCH', "/warehouses/{$second}/default")->assertStatus(422);
        $this->apiAs('PATCH', "/warehouses/{$second}/active", ['is_active' => true])->assertOk();
        $this->apiAs('PATCH', "/warehouses/{$second}/default")->assertOk();
    }
}
