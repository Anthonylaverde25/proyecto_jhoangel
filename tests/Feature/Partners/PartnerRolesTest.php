<?php

declare(strict_types=1);

namespace Tests\Feature\Partners;

use App\Models\Farm;

/**
 * A partner sells to us, buys from us or both (C1); a role in use is not taken away (C2) and every
 * selector filters by the role of its document (C3).
 */
class PartnerRolesTest extends PartnerTestCase
{
    public function test_a_pure_customer_and_a_mixed_partner_are_created(): void
    {
        $customer = $this->createPartner(['roles' => ['CUSTOMER_LIVESTOCK']]);
        $this->assertSame(['CUSTOMER_LIVESTOCK'], $customer['roles']);
        $this->assertTrue($customer['is_customer']);
        $this->assertFalse($customer['is_supplier']);

        $mixed = $this->createPartner(['roles' => ['CUSTOMER_ITEMS', 'SUPPLIER_LIVESTOCK', 'CUSTOMER_LIVESTOCK']]);
        $this->assertSame(['SUPPLIER_LIVESTOCK', 'CUSTOMER_LIVESTOCK', 'CUSTOMER_ITEMS'], $mixed['roles']);
        $this->assertTrue($mixed['is_supplier']);
        $this->assertTrue($mixed['is_customer']);
    }

    public function test_without_roles_it_sells_livestock_as_the_provider_form_did(): void
    {
        $this->assertSame(['SUPPLIER_LIVESTOCK'], $this->createPartner()['roles']);
    }

    public function test_an_empty_or_unknown_role_is_refused(): void
    {
        $this->apiAs('POST', '/partners', ['name' => 'Sin rol', 'cuit' => $this->freshCuit(), 'roles' => []])
            ->assertStatus(422)->assertJsonValidationErrors('roles');
        $this->apiAs('POST', '/partners', ['name' => 'Rol raro', 'cuit' => $this->freshCuit(), 'roles' => ['CARRIER']])
            ->assertStatus(422)->assertJsonValidationErrors('roles.0');
    }

    public function test_roles_change_and_customer_roles_go_freely(): void
    {
        $id = $this->createPartner(['roles' => ['SUPPLIER_LIVESTOCK', 'CUSTOMER_ITEMS']])['id'];

        $this->apiAs('PATCH', "/partners/{$id}/roles", ['roles' => ['SUPPLIER_LIVESTOCK', 'CUSTOMER_LIVESTOCK']])
            ->assertOk()->assertJsonPath('roles', ['SUPPLIER_LIVESTOCK', 'CUSTOMER_LIVESTOCK']);

        $this->apiAs('PATCH', "/partners/{$id}/roles", ['roles' => []])
            ->assertStatus(422)->assertJsonValidationErrors('roles');
    }

    public function test_a_role_in_use_is_not_taken_away(): void
    {
        $seller = $this->createPartner(['roles' => ['SUPPLIER_LIVESTOCK', 'SUPPLIER_ITEMS']]);
        $item = $this->createItem();
        $this->apiAs('PUT', "/items/{$item}/providers", ['providers' => [['provider_id' => $seller['id']]]])->assertOk();
        Farm::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'provider_id' => $seller['id'], 'name' => 'Campo del vendedor', 'is_active' => true,
        ]);

        $this->apiAs('PATCH', "/partners/{$seller['id']}/roles", ['roles' => ['SUPPLIER_LIVESTOCK']])
            ->assertStatus(422)->assertJsonValidationErrors('domain');
        $this->apiAs('PATCH', "/partners/{$seller['id']}/roles", ['roles' => ['SUPPLIER_ITEMS', 'CUSTOMER_LIVESTOCK']])
            ->assertStatus(422)->assertJsonValidationErrors('domain');
    }

    public function test_the_list_filters_by_role_and_by_group(): void
    {
        $customer = $this->createPartner(['roles' => ['CUSTOMER_LIVESTOCK']])['id'];
        $supplier = $this->createPartner(['roles' => ['SUPPLIER_ITEMS']])['id'];

        $ids = fn (string $query) => array_column($this->apiAs('GET', "/partners{$query}")->assertOk()->json(), 'id');

        $this->assertContains($customer, $ids('?role=CUSTOMER_LIVESTOCK'));
        $this->assertNotContains($supplier, $ids('?role=CUSTOMER_LIVESTOCK'));
        $this->assertContains($supplier, $ids('?role[]=SUPPLIER_ITEMS&role[]=CUSTOMER_LIVESTOCK'));
        $this->assertContains($customer, $ids('?role[]=SUPPLIER_ITEMS&role[]=CUSTOMER_LIVESTOCK'));
        $this->assertContains($customer, $ids('?group=customer'));
        $this->assertNotContains($customer, $ids('?group=supplier'));
        $this->assertContains($supplier, $ids('?group=supplier'));
        $this->assertContains($customer, $ids(''));
        $this->assertContains($supplier, $ids(''));
    }

    public function test_the_providers_alias_keeps_its_contract(): void
    {
        $customer = $this->createPartner(['roles' => ['CUSTOMER_LIVESTOCK']])['id'];
        $mixed = $this->createPartner(['roles' => ['SUPPLIER_ITEMS', 'CUSTOMER_LIVESTOCK']])['id'];

        $listed = array_column($this->apiAs('GET', '/providers')->assertOk()->json(), 'id');
        $this->assertNotContains($customer, $listed, 'A pure customer is not a provider.');
        $this->assertContains($mixed, array_column($this->apiAs('GET', '/providers?supplies=items')->json(), 'id'));
        $this->assertNotContains($mixed, array_column($this->apiAs('GET', '/providers?supplies=livestock')->json(), 'id'));

        // The legacy flags only touch supplier roles: the customer role stays.
        $this->apiAs('PATCH', "/providers/{$mixed}/roles", ['supplies_livestock' => true, 'supplies_items' => true])
            ->assertOk()
            ->assertJsonPath('supplies_livestock', true)
            ->assertJsonPath('roles', ['SUPPLIER_LIVESTOCK', 'SUPPLIER_ITEMS', 'CUSTOMER_LIVESTOCK']);
    }

    public function test_a_partner_of_another_company_is_not_found(): void
    {
        $foreign = $this->provider($this->freshCuit(), true, $this->other);

        $this->apiAs('PATCH', "/partners/{$foreign->id}/roles", ['roles' => ['CUSTOMER_LIVESTOCK']])->assertStatus(404);
        $this->apiAs('GET', "/partners/{$foreign->id}")->assertStatus(404);
    }
}
