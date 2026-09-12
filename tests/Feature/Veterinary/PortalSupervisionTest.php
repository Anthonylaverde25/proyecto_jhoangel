<?php

declare(strict_types=1);

namespace Tests\Feature\Veterinary;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The producer can open any professional's portal to see their screens and their work.
 *
 * Reading is the whole point, and also the whole limit: signing, dispatching and reporting are
 * acts somebody attests to, and a manager performing one would put a licence under a document
 * its owner never saw.
 */
class PortalSupervisionTest extends VeterinaryTestCase
{
    public function test_a_manager_could_not_reach_the_portal_at_all_before(): void
    {
        // Without naming a professional there is no "the portal" to open: only somebody's.
        $this->apiAs('GET', '/veterinary-portal/session')->assertStatus(403);
    }

    public function test_a_manager_sees_a_professionals_portal(): void
    {
        $vet = $this->veterinarian('MP 4582');

        $session = $this->apiAs('GET', "/veterinary-portal/session?veterinarian_id={$vet->id}");

        $session->assertStatus(200);
        $session->assertJsonPath('data.veterinarian.license_number', 'MP 4582');

        // Every read surface works, which is what "ver su trabajo" means in practice.
        $this->apiAs('GET', "/veterinary-portal/acts?veterinarian_id={$vet->id}")->assertStatus(200);
        $this->apiAs('GET', "/veterinary-portal/pending-tubes?veterinarian_id={$vet->id}")->assertStatus(200);
        $this->apiAs('GET', "/veterinary-portal/shipments?veterinarian_id={$vet->id}")->assertStatus(200);
    }

    public function test_the_manager_sees_the_same_acts_the_professional_owns(): void
    {
        $vet = $this->veterinarian('MP 4582');

        $asManager = $this->apiAs('GET', "/veterinary-portal/acts?veterinarian_id={$vet->id}");
        $asVet = $this->apiAsUser(
            User::where('email', 'faranguren@ganadero.com')->firstOrFail(),
            'GET',
            '/veterinary-portal/acts'
        );

        $this->assertSame(
            array_column($asVet->json('data.pending_signature') ?? [], 'protocol_number'),
            array_column($asManager->json('data.pending_signature') ?? [], 'protocol_number')
        );
    }

    public function test_a_manager_cannot_sign_in_a_professionals_name(): void
    {
        $vet = $this->veterinarian('MP 4582');
        $actId = (int) DB::table('diagnostic_protocols')
            ->where('protocol_number', 'ACTA-SEED-DRAFT')
            ->value('id');

        $this->apiAs('POST', "/veterinary-portal/acts/{$actId}/sign?veterinarian_id={$vet->id}", [])
            ->assertStatus(403);

        $this->assertNull(
            DB::table('diagnostic_protocols')->where('id', $actId)->value('signed_at'),
            'Mirar no firma.'
        );
    }

    public function test_a_manager_cannot_declare_a_shipment_either(): void
    {
        $vet = $this->veterinarian('MP 4582');

        $this->apiAs('POST', "/veterinary-portal/shipments?veterinarian_id={$vet->id}", [
            'shipped_on' => now()->toDateString(),
            'cold_chain_ok' => true,
            'institution' => ['nombre' => 'Laboratorio Rosario', 'cuit' => '30712345671'],
            'sample_ids' => [1],
        ])->assertStatus(403);

        $this->assertSame(0, DB::table('sample_shipments')->count());
    }

    public function test_a_veterinarian_cannot_borrow_another_ones_portal(): void
    {
        // The door is for management users. A professional peeking at a colleague's portal by
        // naming them in the query string still lands on their own session.
        $roldan = User::where('email', 'croldan@ganadero.com')->firstOrFail();
        $aranguren = $this->veterinarian('MP 4582');

        $session = $this->apiAsUser(
            $roldan,
            'GET',
            "/veterinary-portal/session?veterinarian_id={$aranguren->id}"
        );

        $session->assertStatus(200);
        $session->assertJsonPath('data.veterinarian.license_number', 'MP 5127');
    }
}
