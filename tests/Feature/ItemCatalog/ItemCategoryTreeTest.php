<?php

declare(strict_types=1);

namespace Tests\Feature\ItemCatalog;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class ItemCategoryTreeTest extends ItemCatalogTestCase
{
    private function create(string $name, ?int $parentId = null): int
    {
        return (int) $this->apiAs('POST', '/item-categories', ['name' => $name, 'parent_id' => $parentId])
            ->assertStatus(201)->json('id');
    }

    public function test_a_subcategory_gets_a_code_from_its_name(): void
    {
        $id = $this->create('Antiparasitarios', $this->categoryId('HEALTH'));
        $again = $this->apiAs('POST', '/item-categories', ['name' => 'Antiparasitarios'])->assertStatus(201);

        $this->assertSame('ANTIPARASITARIOS', DB::table('item_categories')->where('id', $id)->value('code'));
        $again->assertJsonPath('code', 'ANTIPARASITARIOS_2');
    }

    public function test_a_category_cannot_move_inside_its_descendant(): void
    {
        $antiparasitics = $this->create('Antiparasitarios', $this->categoryId('HEALTH'));
        $endectocides = $this->create('Endectocidas', $antiparasitics);

        $this->apiAs('PATCH', "/item-categories/{$antiparasitics}", ['parent_id' => $endectocides])
            ->assertStatus(422)->assertJsonValidationErrors('domain');
        $this->apiAs('PATCH', "/item-categories/{$antiparasitics}", ['parent_id' => $antiparasitics])
            ->assertStatus(422);

        // Moving it back to the root is fine.
        $this->apiAs('PATCH', "/item-categories/{$antiparasitics}", ['parent_id' => null])
            ->assertOk()->assertJsonPath('parent_id', null);
    }

    public function test_an_inactive_parent_is_refused(): void
    {
        $this->apiAs('PATCH', '/item-categories/' . $this->categoryId('FUEL') . '/active', ['is_active' => false])->assertOk();

        $this->apiAs('POST', '/item-categories', ['name' => 'Gasoil', 'parent_id' => $this->categoryId('FUEL')])
            ->assertStatus(422)->assertJsonValidationErrors('domain');
    }

    public function test_a_parent_of_another_company_is_refused_by_validation_and_by_the_database(): void
    {
        $this->apiAs('POST', '/item-categories', ['name' => 'Ajena', 'parent_id' => $this->categoryId('HEALTH', $this->other)])
            ->assertStatus(422)->assertJsonValidationErrors('parent_id');

        $this->expectException(QueryException::class);
        DB::table('item_categories')->insert([
            'company_id' => $this->company->id, 'parent_id' => $this->categoryId('HEALTH', $this->other),
            'code' => 'CROSSED', 'name' => 'Cruzada', 'is_active' => true, 'display_order' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
