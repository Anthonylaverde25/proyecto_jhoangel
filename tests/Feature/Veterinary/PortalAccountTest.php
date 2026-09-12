<?php

declare(strict_types=1);

namespace Tests\Feature\Veterinary;

use App\Models\User;
use App\Models\Veterinarian;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Creating a professional creates their way in: one action, and the veterinarian can already
 * enter with the generic password the producer hands over.
 */
class PortalAccountTest extends VeterinaryTestCase
{
    public function test_creating_a_veterinarian_creates_their_portal_account(): void
    {
        $response = $this->apiAs('POST', '/veterinarians', [
            'name' => 'Dra. Valeria Lemos',
            'license_number' => 'MP 8801',
            'email' => 'vlemos@ganadero.com',
            'cuit' => '27314567892',
        ]);

        $response->assertStatus(201);

        $user = User::where('email', 'vlemos@ganadero.com')->first();
        $this->assertNotNull($user, 'El alta del profesional tiene que dejarlo listo para entrar.');

        // The password the producer hands over, straight from configuration.
        $this->assertTrue(Hash::check(
            (string) config('livestock.veterinary_portal.default_password'),
            $user->password
        ));

        $response->assertJsonPath('data.user_id', $user->id);

        $this->assertSame(
            'veterinarian',
            DB::table('company_user')
                ->where('company_id', $this->company->id)
                ->where('user_id', $user->id)
                ->value('role')
        );
    }

    public function test_an_existing_account_keeps_its_own_password(): void
    {
        // The same professional already works for another producer: resetting their password to
        // the generic one would lock them out of everywhere else.
        $existing = User::create([
            'name' => 'Dr. Ya Registrado',
            'email' => 'ya-registrado@ganadero.com',
            'password' => Hash::make('su-clave-de-siempre'),
        ]);

        $this->apiAs('POST', '/veterinarians', [
            'name' => 'Dr. Ya Registrado',
            'license_number' => 'MP 8802',
            'email' => 'ya-registrado@ganadero.com',
        ])->assertStatus(201);

        $existing->refresh();

        $this->assertTrue(Hash::check('su-clave-de-siempre', $existing->password));
        $this->assertSame(1, User::where('email', 'ya-registrado@ganadero.com')->count());
    }

    public function test_a_professional_without_an_email_gets_no_account(): void
    {
        // An account nobody can reach is worse than no account: it holds a known password and
        // no way to tell its owner it exists.
        $response = $this->apiAs('POST', '/veterinarians', [
            'name' => 'M.V. Sin Correo',
            'license_number' => 'MP 8803',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.user_id', null);
    }

    public function test_the_response_tells_the_operator_an_account_exists(): void
    {
        // The sheet's selector creates the professional inline, so the answer has to carry enough
        // for the operator to hand the access over without looking anywhere else.
        $response = $this->apiAs('POST', '/veterinarians', [
            'name' => 'Dr. Alta Desde Planilla',
            'license_number' => 'MP 8805',
            'email' => 'alta-planilla@ganadero.com',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.email', 'alta-planilla@ganadero.com');
        $this->assertNotNull($response->json('data.user_id'));
    }

    public function test_the_new_account_can_actually_open_the_portal(): void
    {
        $this->apiAs('POST', '/veterinarians', [
            'name' => 'Dra. Valeria Lemos',
            'license_number' => 'MP 8804',
            'email' => 'lemos-portal@ganadero.com',
        ])->assertStatus(201);

        $user = User::where('email', 'lemos-portal@ganadero.com')->firstOrFail();

        // The point of the whole change: no second step between the file and the portal.
        $session = $this->apiAsUser($user, 'GET', '/veterinary-portal/session');

        $session->assertStatus(200);
        $session->assertJsonPath('data.veterinarian.license_number', 'MP 8804');
    }
}
