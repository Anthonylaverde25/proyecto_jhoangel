<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Entities\PartnerContactEntity;
use App\Core\Exceptions\PartnerException;
use PHPUnit\Framework\TestCase;

/**
 * A contact person always has a name and a phone; the email is optional but valid (C6).
 */
class PartnerContactEntityTest extends TestCase
{
    public function test_a_contact_without_phone_is_refused(): void
    {
        try {
            new PartnerContactEntity(null, 'Juan', null, '   ', 'juan@test.com', null);
            $this->fail('A contact without phone was accepted.');
        } catch (PartnerException $e) {
            $this->assertArrayHasKey('phone', $e->getErrors());
        }
    }

    public function test_a_contact_without_name_is_refused(): void
    {
        $this->expectException(PartnerException::class);
        new PartnerContactEntity(null, ' ', null, '2494 000000', null, null);
    }

    public function test_an_invalid_email_is_refused(): void
    {
        try {
            new PartnerContactEntity(null, 'Juan', null, '2494 000000', 'no-es-un-email', null);
            $this->fail('An invalid email was accepted.');
        } catch (PartnerException $e) {
            $this->assertArrayHasKey('email', $e->getErrors());
        }
    }

    public function test_blank_optional_fields_become_null_and_the_rest_is_trimmed(): void
    {
        $contact = new PartnerContactEntity(null, ' Juan ', ' ', ' 2494 000000 ', '', '');

        $this->assertSame('Juan', $contact->getName());
        $this->assertSame('2494 000000', $contact->getPhone());
        $this->assertNull($contact->getPosition());
        $this->assertNull($contact->getEmail());
        $this->assertNull($contact->getNotes());
    }

    public function test_an_update_keeps_the_rules(): void
    {
        $contact = new PartnerContactEntity(1, 'Juan', null, '2494 000000', null, null, true);

        $this->expectException(PartnerException::class);
        $contact->updateDetails('Juan', 'Comprador', '', null, null);
    }
}
