<?php

declare(strict_types=1);

namespace Tests\Feature\Providers;

use App\Core\ValueObjects\Cuit;
use App\Models\Provider;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Veterinary\VeterinaryTestCase;

/**
 * A provider's CUIT is the key that identifies the issuer of every fiscal document: it is stored
 * as digits, it must close its check digit, and it stops changing once a document carries it.
 */
class ProviderCuitTest extends VeterinaryTestCase
{
    public function test_the_cuit_is_stored_as_digits(): void
    {
        $digits = $this->freshCuit();

        $this->apiAs('POST', '/providers', ['name' => 'Con guiones', 'cuit' => $this->dashed($digits)])
            ->assertStatus(201)->assertJsonPath('cuit', $digits)->assertJsonPath('cuit_locked', false);
    }

    public function test_a_check_digit_that_does_not_close_is_refused_naming_the_right_one(): void
    {
        $digits = $this->freshCuit();
        $wrong = substr($digits, 0, 10) . (((int) $digits[10] + 1) % 10);

        $this->apiAs('POST', '/providers', ['name' => 'Mal tipeado', 'cuit' => $wrong])
            ->assertStatus(422)
            ->assertJsonPath('errors.cuit.0', "El dígito verificador no cierra: con esos 10 primeros dígitos el CUIT termina en {$digits[10]}.");
    }

    public function test_a_cuit_without_eleven_digits_is_refused(): void
    {
        $this->apiAs('POST', '/providers', ['name' => 'Corto', 'cuit' => '30-1234567-1'])
            ->assertStatus(422)->assertJsonPath('errors.cuit.0', 'El CUIT tiene 11 dígitos; se cargaron 10.');
    }

    public function test_the_same_number_with_other_formatting_is_a_duplicate(): void
    {
        $digits = $this->freshCuit();
        $this->apiAs('POST', '/providers', ['name' => 'Original', 'cuit' => $digits])->assertStatus(201);

        $this->apiAs('POST', '/providers', ['name' => 'Repetido', 'cuit' => $this->dashed($digits)])
            ->assertStatus(422)->assertJsonValidationErrors('cuit');
    }

    public function test_the_cuit_changes_while_no_document_carries_it(): void
    {
        $id = $this->provider();
        $corrected = $this->freshCuit();

        $this->apiAs('PUT', "/providers/{$id}", ['name' => 'Corregido', 'cuit' => $this->dashed($corrected)])
            ->assertOk()->assertJsonPath('cuit', $corrected)->assertJsonPath('name', 'Corregido');
    }

    public function test_the_cuit_is_locked_once_a_document_carries_it_even_voided(): void
    {
        $id = $this->provider();
        $this->document($id, 'VOIDED');

        $this->apiAs('GET', "/providers/{$id}")->assertJsonPath('cuit_locked', true);

        $this->apiAs('PUT', "/providers/{$id}", ['name' => 'Otro', 'cuit' => $this->freshCuit()])
            ->assertStatus(422)
            ->assertJsonPath('errors.cuit.0', 'El CUIT no se puede cambiar: el proveedor ya tiene comprobantes registrados.');
    }

    public function test_other_fields_change_on_a_provider_with_documents(): void
    {
        $id = $this->provider();
        $cuit = (string) Provider::withoutGlobalScopes()->findOrFail($id)->cuit;
        $this->document($id, 'REGISTERED');

        $this->apiAs('PUT', "/providers/{$id}", ['name' => 'Nuevo nombre', 'cuit' => $this->dashed($cuit), 'phone' => '341 555 0000'])
            ->assertOk()->assertJsonPath('name', 'Nuevo nombre')->assertJsonPath('phone', '341 555 0000');
    }

    public function test_ten_digits_that_need_a_ten_have_no_suggested_digit(): void
    {
        // Ten digits whose modulo 11 asks for a 10: no last digit makes them a CUIT.
        $cuit = Cuit::fromString($this->tenDigitsWithoutCuit() . '9');

        $this->assertNull($cuit->expectedCheckDigit());
        $this->assertFalse($cuit->hasValidCheckDigit());
        $this->assertSame('No existe un CUIT con esos 10 primeros dígitos: revisá el número.', $cuit->invalidReason());
    }

    private function provider(): int
    {
        return (int) $this->apiAs('POST', '/providers', ['name' => 'Proveedor CUIT', 'cuit' => $this->freshCuit()])
            ->assertStatus(201)->json('id');
    }

    private function document(int $providerId, string $status): void
    {
        DB::table('fiscal_documents')->insert([
            'company_id' => $this->company->id,
            'provider_id' => $providerId,
            'fiscal_document_type_id' => DB::table('fiscal_document_types')->value('id'),
            'point_of_sale' => 1,
            'number' => random_int(1, 99_999_999),
            'issued_at' => now()->toDateString(),
            'lines_amount' => 0, 'line_taxes_amount' => 0, 'document_taxes_amount' => 0, 'total_amount' => 0,
            'status' => $status,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** A valid CUIT not used yet in this company (the test tenant persists between runs). */
    private function freshCuit(): string
    {
        do {
            $ten = '30' . str_pad((string) random_int(0, 99_999_999), 8, '0', STR_PAD_LEFT);
            $expected = Cuit::fromString($ten . '0')->expectedCheckDigit();
            $digits = $ten . $expected;
        } while ($expected === null || Provider::withoutGlobalScopes()->where('company_id', $this->company->id)->where('cuit', $digits)->exists());

        return $digits;
    }

    private function tenDigitsWithoutCuit(): string
    {
        do {
            $ten = '30' . str_pad((string) random_int(0, 99_999_999), 8, '0', STR_PAD_LEFT);
        } while (Cuit::fromString($ten . '0')->expectedCheckDigit() !== null);

        return $ten;
    }

    private function dashed(string $digits): string
    {
        return substr($digits, 0, 2) . '-' . substr($digits, 2, 8) . '-' . substr($digits, 10);
    }
}
