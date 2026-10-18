<?php

declare(strict_types=1);

namespace Tests\Feature\ItemCatalog;

class ItemPresentationTest extends ItemCatalogTestCase
{
    public function test_a_presentation_converts_a_package_into_the_item_unit(): void
    {
        $id = $this->createItem();

        $this->apiAs('POST', "/items/{$id}/presentations", ['name' => 'Frasco × 500 ml', 'factor_to_item_unit' => 500])
            ->assertStatus(201)->assertJsonPath('factor_to_item_unit', '500.0000');

        $this->apiAs('GET', "/items/{$id}")->assertJsonPath('presentations.0.name', 'Frasco × 500 ml')
            ->assertJsonPath('presentations_count', 1);
    }

    public function test_the_name_is_unique_within_the_item_only(): void
    {
        $first = $this->createItem();
        $second = $this->createItem(['name' => 'Ivermectina 3,15%']);

        $this->apiAs('POST', "/items/{$first}/presentations", ['name' => 'Frasco × 500 ml', 'factor_to_item_unit' => 500])->assertStatus(201);
        $this->apiAs('POST', "/items/{$first}/presentations", ['name' => 'Frasco × 500 ml', 'factor_to_item_unit' => 500])
            ->assertStatus(422)->assertJsonValidationErrors('domain');
        $this->apiAs('POST', "/items/{$second}/presentations", ['name' => 'Frasco × 500 ml', 'factor_to_item_unit' => 500])->assertStatus(201);
    }

    public function test_a_presentation_holds_more_than_zero(): void
    {
        $id = $this->createItem();

        $this->apiAs('POST', "/items/{$id}/presentations", ['name' => 'Vacío', 'factor_to_item_unit' => 0])
            ->assertStatus(422)->assertJsonValidationErrors('factor_to_item_unit');
    }

    public function test_it_is_edited_deactivated_and_reactivated(): void
    {
        $id = $this->createItem();
        $presentation = (int) $this->apiAs('POST', "/items/{$id}/presentations", ['name' => 'Frasco', 'factor_to_item_unit' => 50])->json('id');

        $this->apiAs('PATCH', "/items/{$id}/presentations/{$presentation}", ['name' => 'Frasco × 50 ml'])
            ->assertOk()->assertJsonPath('name', 'Frasco × 50 ml');
        $this->apiAs('PATCH', "/items/{$id}/presentations/{$presentation}/active", ['is_active' => false])
            ->assertOk()->assertJsonPath('is_active', false);
        $this->apiAs('PATCH', "/items/{$id}/presentations/{$presentation}/active", ['is_active' => true])
            ->assertOk()->assertJsonPath('is_active', true);
    }

    public function test_an_inactive_item_takes_no_new_presentations(): void
    {
        $id = $this->createItem();
        $this->apiAs('PATCH', "/items/{$id}/active", ['is_active' => false])->assertOk();

        $this->apiAs('POST', "/items/{$id}/presentations", ['name' => 'Frasco', 'factor_to_item_unit' => 50])
            ->assertStatus(422)->assertJsonValidationErrors('domain');
    }
}
