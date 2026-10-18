<?php

declare(strict_types=1);

namespace Tests\Feature\ItemCatalog;

class UnitOfMeasureCatalogTest extends ItemCatalogTestCase
{
    private function arroba(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->apiAs('POST', '/units-of-measure', $overrides + [
            'code' => 'arr', 'name' => 'Arroba', 'symbol' => '@', 'dimension' => 'MASS', 'factor_to_base' => 11.5,
        ]);
    }

    public function test_a_company_adds_its_own_convertible_unit(): void
    {
        $this->arroba()->assertStatus(201)
            ->assertJsonPath('code', 'ARR')
            ->assertJsonPath('is_base', false)
            ->assertJsonPath('is_convertible', true);
    }

    public function test_the_code_is_unique_per_company_only(): void
    {
        $this->arroba()->assertStatus(201);
        $this->arroba()->assertStatus(422)->assertJsonValidationErrors('code');
        $this->asOther(fn () => $this->arroba())->assertStatus(201);
    }

    public function test_the_factor_must_be_positive_or_absent(): void
    {
        $this->arroba(['factor_to_base' => 0])->assertStatus(422)->assertJsonValidationErrors('factor_to_base');

        $this->apiAs('POST', '/units-of-measure', [
            'code' => 'ROLL', 'name' => 'Rollo', 'symbol' => 'rollo', 'dimension' => 'COUNT', 'factor_to_base' => null,
        ])->assertStatus(201)->assertJsonPath('is_convertible', false);
    }

    public function test_dimension_and_factor_freeze_once_an_item_uses_the_unit(): void
    {
        $unitId = (int) $this->arroba()->json('id');
        $this->createItem(['name' => 'Rollo de alfalfa', 'unit_of_measure_id' => $unitId]);

        $this->apiAs('PATCH', "/units-of-measure/{$unitId}", ['factor_to_base' => 12])
            ->assertStatus(422)->assertJsonValidationErrors('domain');

        // Renaming stays possible.
        $this->apiAs('PATCH', "/units-of-measure/{$unitId}", ['name' => 'Arroba española'])
            ->assertOk()->assertJsonPath('name', 'Arroba española');
    }

    public function test_a_base_unit_is_never_deactivated(): void
    {
        $this->apiAs('PATCH', '/units-of-measure/' . $this->unitId('KG') . '/active', ['is_active' => false])
            ->assertStatus(422)->assertJsonValidationErrors('domain');
    }

    public function test_the_unit_of_an_active_item_is_not_deactivated(): void
    {
        $this->createItem();

        $this->apiAs('PATCH', '/units-of-measure/' . $this->unitId('ML') . '/active', ['is_active' => false])
            ->assertStatus(422)->assertJsonValidationErrors('domain');
        $this->apiAs('PATCH', '/units-of-measure/' . $this->unitId('G') . '/active', ['is_active' => false])
            ->assertOk()->assertJsonPath('is_active', false);
    }

    public function test_a_unit_of_another_company_is_not_found(): void
    {
        $this->apiAs('PATCH', '/units-of-measure/' . $this->unitId('G', $this->other), ['name' => 'Ajeno'])
            ->assertStatus(404);
    }
}
