<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Entities\PartnerContactEntity;
use App\Core\Entities\PartnerEntity;
use App\Core\Enums\PartnerRole;
use App\Core\Exceptions\PartnerException;
use PHPUnit\Framework\TestCase;

/**
 * A partner holds at least one role (C1) and at most one default contact, which only goes when it
 * is the last one (C7–C9).
 */
class PartnerEntityTest extends TestCase
{
    /** @param list<PartnerRole> $roles */
    private function partner(array $roles = [PartnerRole::SUPPLIER_LIVESTOCK]): PartnerEntity
    {
        return new PartnerEntity(1, 'Frigorífico Test', null, '30712345678', null, $roles, companyId: 1);
    }

    private function contact(?int $id, string $name = 'Juan', bool $isDefault = false): PartnerContactEntity
    {
        return new PartnerContactEntity($id, $name, null, '2494 000000', null, null, $isDefault);
    }

    public function test_a_partner_without_roles_is_refused(): void
    {
        $this->expectException(PartnerException::class);
        $this->partner([]);
    }

    public function test_roles_cannot_be_emptied(): void
    {
        $partner = $this->partner();

        $this->expectException(PartnerException::class);
        $partner->changeRoles([]);
    }

    public function test_supplier_and_customer_follow_the_roles(): void
    {
        $partner = $this->partner([PartnerRole::CUSTOMER_LIVESTOCK, PartnerRole::SUPPLIER_LIVESTOCK, PartnerRole::CUSTOMER_LIVESTOCK]);

        $this->assertTrue($partner->isSupplier());
        $this->assertTrue($partner->isCustomer());
        $this->assertTrue($partner->hasRole(PartnerRole::CUSTOMER_LIVESTOCK));
        $this->assertFalse($partner->hasRole(PartnerRole::SUPPLIER_ITEMS));
        $this->assertSame([PartnerRole::SUPPLIER_LIVESTOCK, PartnerRole::CUSTOMER_LIVESTOCK], $partner->getRoles());

        $partner->changeRoles([PartnerRole::CUSTOMER_ITEMS]);
        $this->assertFalse($partner->isSupplier());
        $this->assertTrue($partner->isCustomer());
    }

    public function test_the_first_contact_becomes_the_default_and_the_next_does_not(): void
    {
        $partner = $this->partner();

        $first = $partner->addContact($this->contact(null, 'Comprador'));
        $second = $partner->addContact($this->contact(null, 'Administración', isDefault: true));

        $this->assertTrue($first->isDefault());
        $this->assertFalse($second->isDefault());
        $this->assertSame($first, $partner->defaultContact());
    }

    public function test_marking_another_default_moves_the_mark(): void
    {
        $partner = new PartnerEntity(1, 'Frigorífico Test', null, '30712345678', null, [PartnerRole::CUSTOMER_LIVESTOCK],
            contacts: [$this->contact(1, 'Comprador', true), $this->contact(2, 'Administración')]);

        $partner->setDefaultContact(2);

        $this->assertSame(2, $partner->defaultContact()?->getId());
        $this->assertFalse($partner->findContact(1)?->isDefault());
        $this->assertSame([2, 1], array_map(fn (PartnerContactEntity $c) => $c->getId(), $partner->getContacts()));
    }

    public function test_the_default_contact_does_not_go_while_others_remain(): void
    {
        $partner = new PartnerEntity(1, 'Frigorífico Test', null, '30712345678', null, [PartnerRole::CUSTOMER_LIVESTOCK],
            contacts: [$this->contact(1, 'Comprador', true), $this->contact(2, 'Administración')]);

        try {
            $partner->removeContact(1);
            $this->fail('The default contact went while another one remained.');
        } catch (PartnerException $e) {
            $this->assertArrayHasKey('contact', $e->getErrors());
        }

        $partner->removeContact(2);
        $partner->removeContact(1);
        $this->assertSame([], $partner->getContacts());
        $this->assertNull($partner->defaultContact());
    }
}
