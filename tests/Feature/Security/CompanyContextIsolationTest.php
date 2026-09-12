<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Company;
use App\Models\User;
use Tests\Feature\Veterinary\VeterinaryTestCase;

/**
 * ADR-7 security prerequisite. `CompanyContextMiddleware` used to trust `X-Company-ID` blindly,
 * so any authenticated user could read and write another company's records by changing one
 * header. Moving legally binding sanitary evidence over that is not acceptable.
 */
class CompanyContextIsolationTest extends VeterinaryTestCase
{
    public function test_a_user_cannot_operate_on_a_company_they_do_not_belong_to(): void
    {
        $foreignCompany = Company::create([
            'name' => 'Establecimiento Ajeno',
            'cuit' => '30-70000000-1',
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-ID', (string) $foreignCompany->id)
            ->getJson($this->url('/diagnostic-protocols'))
            ->assertStatus(403);
    }

    public function test_a_member_reaches_their_own_company_normally(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-ID', (string) $this->company->id)
            ->getJson($this->url('/diagnostic-protocols'))
            ->assertStatus(200);
    }

    public function test_membership_is_checked_per_user_not_per_company(): void
    {
        $outsider = User::create([
            'name' => 'Usuario Externo',
            'email' => 'externo@prueba.com',
            'password' => bcrypt('secret123'),
        ]);

        $this->actingAs($outsider, 'sanctum')
            ->withHeader('X-Company-ID', (string) $this->company->id)
            ->getJson($this->url('/diagnostic-protocols'))
            ->assertStatus(403);
    }
}
