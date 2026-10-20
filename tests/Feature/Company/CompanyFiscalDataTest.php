<?php

declare(strict_types=1);

namespace Tests\Feature\Company;

use App\Models\Company;
use Tests\Feature\Veterinary\VeterinaryTestCase;

/**
 * The company's own CUIT is the receiver of its providers' documents: stored as digits, valid
 * when given, clearable, and shared by companies of the same owner.
 */
class CompanyFiscalDataTest extends VeterinaryTestCase
{
    public function test_the_cuit_is_stored_as_digits(): void
    {
        $this->apiAs('PATCH', '/company/fiscal-data', ['cuit' => '30-71700002-8'])
            ->assertOk()->assertJsonPath('cuit', '30717000028');

        $this->assertSame('30717000028', Company::findOrFail($this->company->id)->cuit);
    }

    public function test_a_check_digit_that_does_not_close_is_refused(): void
    {
        $this->apiAs('PATCH', '/company/fiscal-data', ['cuit' => '30-71700002-9'])
            ->assertStatus(422)
            ->assertJsonPath('errors.cuit.0', 'El dígito verificador no cierra: con esos 10 primeros dígitos el CUIT termina en 8.');
    }

    public function test_null_or_empty_clears_it(): void
    {
        $this->apiAs('PATCH', '/company/fiscal-data', ['cuit' => '30717000028'])->assertOk();

        $this->apiAs('PATCH', '/company/fiscal-data', ['cuit' => ''])->assertOk()->assertJsonPath('cuit', null);
        $this->assertNull(Company::findOrFail($this->company->id)->cuit);
    }

    public function test_two_companies_may_share_the_owner_cuit_and_only_the_active_one_changes(): void
    {
        $other = Company::where('id', '!=', $this->company->id)->firstOrFail();
        $other->fill(['cuit' => '30717000036'])->save();

        $this->apiAs('PATCH', '/company/fiscal-data', ['cuit' => '30717000036'])->assertOk();

        $this->assertSame('30717000036', Company::findOrFail($this->company->id)->cuit);
        $this->assertSame('30717000036', $other->fresh()->cuit);
    }
}
