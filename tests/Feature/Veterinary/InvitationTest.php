<?php

declare(strict_types=1);

namespace Tests\Feature\Veterinary;

use App\Models\User;
use App\Models\Veterinarian;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * ADR-33: every veterinarian can be a portal user, and their credential is born from an
 * invitation they accept themselves.
 */
class InvitationTest extends VeterinaryTestCase
{
    public function test_professional_sets_their_own_password(): void
    {
        // Sosa has a catalogue file and no account: the state v9 exists to resolve.
        $sosa = $this->veterinarian('MP 6710');
        $this->assertNull($sosa->user_id);

        $invitation = $this->apiAs('POST', "/veterinarians/{$sosa->id}/invite", [
            'email' => 'mariela.sosa@vetsur.com.ar',
        ]);

        $invitation->assertStatus(201);
        $acceptUrl = (string) $invitation->json('data.accept_url');
        $token = substr($acceptUrl, strrpos($acceptUrl, '/') + 1);

        // The professional sees who invited them before choosing anything.
        $this->getJson($this->url("/invitations/{$token}"))
            ->assertStatus(200)
            ->assertJsonPath('data.license_number', 'MP 6710');

        $this->postJson($this->url("/invitations/{$token}/accept"), [
            'password' => 'una-clave-suya',
            'password_confirmation' => 'una-clave-suya',
        ])->assertStatus(200);

        $user = User::where('email', 'mariela.sosa@vetsur.com.ar')->firstOrFail();

        // The producer never learns it: it is hashed, and they never typed it.
        $this->assertTrue(Hash::check('una-clave-suya', $user->password));

        $this->assertSame(
            $user->id,
            (int) Veterinarian::withoutGlobalScopes()->find($sosa->id)->user_id
        );

        $this->assertSame(
            'veterinarian',
            DB::table('company_user')
                ->where('company_id', $this->company->id)
                ->where('user_id', $user->id)
                ->value('role')
        );
    }

    public function test_an_existing_user_is_linked_not_duplicated(): void
    {
        // The same professional working for a second producer: duplicating the person would
        // split their history in two.
        $quiroga = $this->veterinarian('MP 3391');
        $existing = User::create([
            'name' => 'Dr. Hernán Quiroga',
            'email' => 'hquiroga@ruraldelsur.com.ar',
            'password' => Hash::make('ya-tenia-clave'),
        ]);

        $invitation = $this->apiAs('POST', "/veterinarians/{$quiroga->id}/invite", []);
        $acceptUrl = (string) $invitation->json('data.accept_url');
        $token = substr($acceptUrl, strrpos($acceptUrl, '/') + 1);

        $this->postJson($this->url("/invitations/{$token}/accept"), [
            'password' => 'otra-clave-distinta',
            'password_confirmation' => 'otra-clave-distinta',
        ])->assertStatus(200);

        $this->assertSame(
            1,
            User::where('email', 'hquiroga@ruraldelsur.com.ar')->count(),
            'La invitación vincula al usuario existente, no crea un segundo.'
        );

        $this->assertSame(
            $existing->id,
            (int) Veterinarian::withoutGlobalScopes()->find($quiroga->id)->user_id
        );
    }

    public function test_an_invitation_is_usable_once(): void
    {
        $sosa = $this->veterinarian('MP 6710');

        $invitation = $this->apiAs('POST', "/veterinarians/{$sosa->id}/invite", [
            'email' => 'sosa-unica@vetsur.com.ar',
        ]);
        $acceptUrl = (string) $invitation->json('data.accept_url');
        $token = substr($acceptUrl, strrpos($acceptUrl, '/') + 1);

        $this->postJson($this->url("/invitations/{$token}/accept"), [
            'password' => 'clave-valida-1',
            'password_confirmation' => 'clave-valida-1',
        ])->assertStatus(200);

        // Used once and done: a link that keeps working is a credential nobody can revoke.
        $this->postJson($this->url("/invitations/{$token}/accept"), [
            'password' => 'clave-valida-2',
            'password_confirmation' => 'clave-valida-2',
        ])->assertStatus(422);

        $this->getJson($this->url("/invitations/{$token}"))->assertStatus(410);
    }

    public function test_issuing_a_new_invitation_supersedes_the_previous_one(): void
    {
        $sosa = $this->veterinarian('MP 6710');

        $first = $this->apiAs('POST', "/veterinarians/{$sosa->id}/invite", ['email' => 'sosa@vetsur.com.ar']);
        $firstUrl = (string) $first->json('data.accept_url');
        $firstToken = substr($firstUrl, strrpos($firstUrl, '/') + 1);

        $this->apiAs('POST', "/veterinarians/{$sosa->id}/invite", ['email' => 'sosa@vetsur.com.ar'])
            ->assertStatus(201);

        // Two working secrets for the same door means nobody can say which one was used.
        $this->getJson($this->url("/invitations/{$firstToken}"))->assertStatus(410);
    }

    public function test_a_professional_without_an_email_cannot_be_invited(): void
    {
        $anonymous = Veterinarian::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'name' => 'M.V. Sin Correo',
            'license_number' => 'MP 9100',
            'is_active' => true,
        ]);

        $this->apiAs('POST', "/veterinarians/{$anonymous->id}/invite", [])->assertStatus(422);
    }
}
