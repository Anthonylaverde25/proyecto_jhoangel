<?php

declare(strict_types=1);

namespace Tests\Feature\Partners;

/**
 * Contact persons of a partner (C6–C9): name and phone always, exactly one default while there are
 * any, and the default goes only when it is the last one.
 */
class PartnerContactsTest extends PartnerTestCase
{
    public function test_the_contact_sent_with_a_new_partner_is_its_default(): void
    {
        $partner = $this->createPartner(['contact' => ['name' => 'Juan Pérez', 'phone' => '2494 111111', 'email' => 'juan@frigorifico.test', 'position' => 'Comprador']]);

        $this->assertSame('Juan Pérez', $partner['default_contact']['name']);
        $this->assertTrue($partner['default_contact']['is_default']);
        $this->assertCount(1, $partner['contacts']);
        // Transitional fields of the /providers shape come from the default contact.
        $this->assertSame('2494 111111', $partner['phone']);
        $this->assertSame('juan@frigorifico.test', $partner['email']);
    }

    public function test_a_partner_may_have_no_contacts(): void
    {
        $partner = $this->createPartner();

        $this->assertNull($partner['default_contact']);
        $this->assertSame([], $partner['contacts']);
        $this->assertNull($partner['phone']);
    }

    public function test_the_phone_is_mandatory(): void
    {
        $this->apiAs('POST', '/partners', ['name' => 'Sin teléfono', 'cuit' => $this->freshCuit(), 'contact' => ['name' => 'Juan', 'email' => 'juan@test.com']])
            ->assertStatus(422)->assertJsonValidationErrors('contact.phone');

        $id = $this->createPartner()['id'];
        $this->apiAs('POST', "/partners/{$id}/contacts", ['name' => 'Juan', 'phone' => ''])
            ->assertStatus(422)->assertJsonValidationErrors('phone');
        $this->apiAs('POST', "/partners/{$id}/contacts", ['name' => 'Juan', 'phone' => '2494', 'email' => 'no-es-email'])
            ->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_the_first_contact_is_the_default_and_the_next_ones_are_not(): void
    {
        $id = $this->createPartner()['id'];

        $first = $this->apiAs('POST', "/partners/{$id}/contacts", ['name' => 'Comprador', 'phone' => '2494 1'])
            ->assertStatus(201)->assertJsonPath('is_default', true)->json('id');
        $this->apiAs('POST', "/partners/{$id}/contacts", ['name' => 'Administración', 'phone' => '2494 2', 'email' => 'admin@test.com'])
            ->assertStatus(201)->assertJsonPath('is_default', false);

        $this->apiAs('GET', "/partners/{$id}")
            ->assertJsonPath('default_contact.id', $first)
            ->assertJsonCount(2, 'contacts')
            ->assertJsonPath('contacts.0.id', $first);
    }

    public function test_the_same_email_may_belong_to_people_of_two_partners(): void
    {
        $contact = ['name' => 'Consignatario', 'phone' => '2494 3', 'email' => 'consignatario@test.com'];

        $this->createPartner(['contact' => $contact]);
        $this->createPartner(['contact' => $contact]);
    }

    public function test_marking_another_default_moves_the_mark(): void
    {
        $id = $this->createPartner(['contact' => ['name' => 'Comprador', 'phone' => '2494 1']])['id'];
        $second = $this->apiAs('POST', "/partners/{$id}/contacts", ['name' => 'Administración', 'phone' => '2494 2'])->json('id');

        $this->apiAs('PATCH', "/partners/{$id}/contacts/{$second}/default")
            ->assertOk()
            ->assertJsonPath('default_contact.id', $second)
            ->assertJsonPath('contacts.0.id', $second)
            ->assertJsonPath('contacts.1.is_default', false);
    }

    public function test_the_default_goes_only_when_it_is_the_last_one(): void
    {
        $partner = $this->createPartner(['contact' => ['name' => 'Comprador', 'phone' => '2494 1']]);
        $default = $partner['default_contact']['id'];
        $id = $partner['id'];
        $other = $this->apiAs('POST', "/partners/{$id}/contacts", ['name' => 'Administración', 'phone' => '2494 2'])->json('id');

        $this->apiAs('DELETE', "/partners/{$id}/contacts/{$default}")
            ->assertStatus(422)->assertJsonValidationErrors('contact');

        $this->apiAs('DELETE', "/partners/{$id}/contacts/{$other}")->assertNoContent();
        $this->apiAs('DELETE', "/partners/{$id}/contacts/{$default}")->assertNoContent();
        $this->apiAs('GET', "/partners/{$id}")->assertJsonPath('default_contact', null)->assertJsonCount(0, 'contacts');
    }

    public function test_a_contact_is_corrected(): void
    {
        $partner = $this->createPartner(['contact' => ['name' => 'Principal', 'phone' => '2494 1']]);
        $contact = $partner['default_contact']['id'];

        $this->apiAs('PUT', "/partners/{$partner['id']}/contacts/{$contact}", ['name' => 'Juan Pérez', 'phone' => '2494 9', 'position' => 'Comprador'])
            ->assertOk()->assertJsonPath('name', 'Juan Pérez')->assertJsonPath('position', 'Comprador')->assertJsonPath('is_default', true);
    }

    public function test_a_contact_of_another_partner_or_company_is_not_found(): void
    {
        $mine = $this->createPartner()['id'];
        $otherPartner = $this->createPartner(['contact' => ['name' => 'Ajeno', 'phone' => '1']]);
        $foreign = $this->provider($this->freshCuit(), true, $this->other);

        $this->apiAs('PUT', "/partners/{$mine}/contacts/{$otherPartner['default_contact']['id']}", ['name' => 'X', 'phone' => '1'])
            ->assertStatus(404);
        $this->apiAs('POST', "/partners/{$foreign->id}/contacts", ['name' => 'X', 'phone' => '1'])->assertStatus(404);
    }

    public function test_the_providers_alias_writes_the_default_contact(): void
    {
        $id = $this->apiAs('POST', '/providers', ['name' => 'Alias', 'cuit' => $this->freshCuit()])->assertStatus(201)->json('id');
        $cuit = $this->apiAs('GET', "/partners/{$id}")->json('cuit');

        $this->apiAs('PUT', "/providers/{$id}", ['name' => 'Alias', 'cuit' => $cuit, 'phone' => '341 555 0000', 'email' => 'alias@test.com'])
            ->assertOk()->assertJsonPath('phone', '341 555 0000')->assertJsonPath('default_contact.name', 'Principal');
        $this->apiAs('PUT', "/providers/{$id}", ['name' => 'Alias', 'cuit' => $cuit, 'phone' => '341 555 1111'])
            ->assertOk()->assertJsonPath('phone', '341 555 1111')->assertJsonCount(1, 'contacts');

        $this->apiAs('PUT', "/providers/{$id}", ['name' => 'Alias', 'cuit' => $cuit, 'email' => 'solo@test.com'])
            ->assertStatus(422)->assertJsonValidationErrors('phone');
    }

    public function test_the_list_carries_the_default_contact(): void
    {
        $id = $this->createPartner(['contact' => ['name' => 'Comprador', 'phone' => '2494 7']])['id'];

        $row = collect($this->apiAs('GET', '/partners')->assertOk()->json())->firstWhere('id', $id);
        $this->assertSame('2494 7', $row['default_contact']['phone']);
    }
}
