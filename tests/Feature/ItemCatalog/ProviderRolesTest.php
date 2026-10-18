<?php

declare(strict_types=1);

namespace Tests\Feature\ItemCatalog;

use App\Models\Farm;

/**
 * What a provider sells can change, but never out from under what depends on it (DB5).
 */
class ProviderRolesTest extends ItemCatalogTestCase
{
    public function test_roles_change(): void
    {
        $provider = $this->provider('20-40000001-1', false);

        $this->apiAs('PATCH', "/providers/{$provider->id}/roles", ['supplies_livestock' => true, 'supplies_items' => true])
            ->assertOk()->assertJsonPath('supplies_items', true)->assertJsonPath('supplies_livestock', true);
    }

    public function test_a_provider_keeps_at_least_one_role(): void
    {
        $provider = $this->provider('20-40000001-1');

        $this->apiAs('PATCH', "/providers/{$provider->id}/roles", ['supplies_livestock' => false, 'supplies_items' => false])
            ->assertStatus(422);
    }

    public function test_a_provider_linked_to_items_keeps_selling_items(): void
    {
        $provider = $this->provider('20-40000001-1');
        $item = $this->createItem();
        $this->apiAs('PUT', "/items/{$item}/providers", ['providers' => [['provider_id' => $provider->id]]])->assertOk();

        $this->apiAs('PATCH', "/providers/{$provider->id}/roles", ['supplies_livestock' => true, 'supplies_items' => false])
            ->assertStatus(422)->assertJsonValidationErrors('domain');
    }

    public function test_a_provider_with_origin_farms_keeps_selling_livestock(): void
    {
        $provider = $this->provider('20-40000001-1', false);
        Farm::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'provider_id' => $provider->id, 'name' => 'Campo del vendedor', 'is_active' => true,
        ]);

        $this->apiAs('PATCH', "/providers/{$provider->id}/roles", ['supplies_livestock' => false, 'supplies_items' => true])
            ->assertStatus(422)->assertJsonValidationErrors('domain');
    }

    public function test_a_provider_of_another_company_is_not_found(): void
    {
        $foreign = $this->provider('20-40000007-7', true, $this->other);

        $this->apiAs('PATCH', "/providers/{$foreign->id}/roles", ['supplies_livestock' => true, 'supplies_items' => true])
            ->assertStatus(404);
    }
}
