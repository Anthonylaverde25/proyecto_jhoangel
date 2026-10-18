<?php

declare(strict_types=1);

namespace Tests\Feature\ItemCatalog;

use App\Core\Entities\ItemEntity;
use App\Core\Enums\ItemType;
use App\Core\Exceptions\ItemCatalogException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class ItemCatalogTest extends ItemCatalogTestCase
{
    public function test_the_three_behaviors_map_to_type_and_stock(): void
    {
        $stocked = $this->apiAs('GET', '/items/' . $this->createItem())->json();
        $expense = $this->apiAs('GET', '/items/' . $this->createItem(['name' => 'Alambre', 'behavior' => 'EXPENSE_ARTICLE', 'unit_of_measure_id' => $this->unitId('UNIT')]))->json();
        $service = $this->apiAs('GET', '/items/' . $this->createItem(['name' => 'Visita', 'behavior' => 'SERVICE', 'unit_of_measure_id' => $this->unitId('SERVICE')]))->json();

        $this->assertSame(['ARTICLE', true], [$stocked['type'], $stocked['track_stock']]);
        $this->assertSame(['ARTICLE', false], [$expense['type'], $expense['track_stock']]);
        $this->assertSame(['SERVICE', false], [$service['type'], $service['track_stock']]);
    }

    public function test_an_empty_code_takes_the_next_one_of_its_type(): void
    {
        $this->createItem(['name' => 'Sal', 'code' => 'ART-0090']);
        $next = $this->createItem(['name' => 'Vacuna']);
        $this->createItem(['name' => 'Flete', 'code' => 'SRV-0050', 'behavior' => 'SERVICE', 'unit_of_measure_id' => $this->unitId('SERVICE')]);
        $service = $this->createItem(['name' => 'Flete corto', 'behavior' => 'SERVICE', 'unit_of_measure_id' => $this->unitId('SERVICE')]);

        $this->apiAs('GET', "/items/{$next}")->assertJsonPath('code', 'ART-0091');
        $this->apiAs('GET', "/items/{$service}")->assertJsonPath('code', 'SRV-0051');

        // Each company counts on its own.
        $first = (int) $this->asOther(fn () => $this->apiAs('POST', '/items', [
            'name' => 'Otro', 'behavior' => 'STOCKED_ARTICLE', 'unit_of_measure_id' => $this->unitId('KG', $this->other),
        ]))->assertStatus(201)->json('id');
        $this->assertSame('ART-0001', \App\Models\Item::withoutGlobalScopes()->whereKey($first)->value('code'));
    }

    public function test_a_repeated_code_is_refused(): void
    {
        $this->createItem(['code' => 'iver1']);

        $this->apiAs('POST', '/items', ['name' => 'Otra', 'behavior' => 'STOCKED_ARTICLE', 'unit_of_measure_id' => $this->unitId('ML'), 'code' => 'IVER1'])
            ->assertStatus(422)->assertJsonValidationErrors('code');
    }

    public function test_a_unit_or_category_of_another_company_is_refused(): void
    {
        $this->apiAs('POST', '/items', ['name' => 'X', 'behavior' => 'SERVICE', 'unit_of_measure_id' => $this->unitId('SERVICE', $this->other)])
            ->assertStatus(422)->assertJsonValidationErrors('unit_of_measure_id');
        $this->apiAs('POST', '/items', ['name' => 'X', 'behavior' => 'SERVICE', 'unit_of_measure_id' => $this->unitId('SERVICE'), 'item_category_id' => $this->categoryId('FREIGHT', $this->other)])
            ->assertStatus(422)->assertJsonValidationErrors('item_category_id');

        $this->expectException(QueryException::class);
        DB::table('items')->insert([
            'company_id' => $this->company->id, 'unit_of_measure_id' => $this->unitId('KG', $this->other),
            'code' => 'CROSSED', 'name' => 'Cruzado', 'type' => 'ARTICLE', 'track_stock' => false, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_the_list_filters_by_behavior_category_search_and_state(): void
    {
        $root = (int) $this->apiAs('POST', '/item-categories', ['name' => 'Zeta sanidad'])->assertStatus(201)->json('id');
        $sub = (int) $this->apiAs('POST', '/item-categories', ['name' => 'Zeta vacunas', 'parent_id' => $root])->json('id');
        $this->createItem(['name' => 'Zeta ivermectina', 'item_category_id' => $root]);
        $this->createItem(['name' => 'Zeta visita', 'behavior' => 'SERVICE', 'unit_of_measure_id' => $this->unitId('SERVICE')]);
        $vaccine = $this->createItem(['name' => 'Zeta aftosa', 'unit_of_measure_id' => $this->unitId('DOSE'), 'item_category_id' => $sub]);
        $this->apiAs('PATCH', "/items/{$vaccine}/active", ['is_active' => false])->assertOk();

        $names = fn (string $query) => array_column($this->apiAs('GET', '/items' . $query)->assertOk()->json(), 'name');

        $this->assertSame(['Zeta visita'], $names('?search=zeta&behavior=SERVICE'));
        $this->assertSame(['Zeta ivermectina'], $names("?category_id={$root}"));
        $this->assertSame(['Zeta aftosa', 'Zeta ivermectina'], $names("?include_inactive=1&category_id={$root}"));
        $this->assertSame(['Zeta ivermectina', 'Zeta visita'], $names('?search=zeta'));
        $this->assertSame('Zeta sanidad › Zeta vacunas', $this->apiAs('GET', "/items/{$vaccine}")->json('category.path'));
    }

    public function test_an_item_of_another_company_is_not_found(): void
    {
        $id = $this->createItem();

        $this->asOther(fn () => $this->apiAs('GET', "/items/{$id}"))->assertStatus(404);
    }

    public function test_a_service_never_tracks_stock_even_without_validation(): void
    {
        $this->expectException(ItemCatalogException::class);

        new ItemEntity(null, (int) $this->company->id, 'X', 'Servicio con stock', null, ItemType::SERVICE, true, $this->unitId('SERVICE'), null);
    }

    public function test_editing_changes_behavior_unit_and_clears_the_category(): void
    {
        $id = $this->createItem(['item_category_id' => $this->categoryId('HEALTH')]);

        $this->apiAs('PATCH', "/items/{$id}", ['behavior' => 'EXPENSE_ARTICLE', 'unit_of_measure_id' => $this->unitId('L'), 'item_category_id' => null])
            ->assertOk()
            ->assertJsonPath('behavior', 'EXPENSE_ARTICLE')
            ->assertJsonPath('unit.code', 'L')
            ->assertJsonPath('category', null);
    }
}
