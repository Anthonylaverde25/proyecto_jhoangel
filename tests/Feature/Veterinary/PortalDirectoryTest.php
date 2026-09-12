<?php

declare(strict_types=1);

namespace Tests\Feature\Veterinary;

use App\Models\User;
use App\Models\Veterinarian;
use Illuminate\Support\Facades\DB;

/**
 * The establishment's directory of portals: one row per professional.
 *
 * The two screens that existed answered adjacent questions. The access manager is organised by
 * TOKEN, so a professional holding no key does not appear in it at all; the supervision dropdown
 * names people but says nothing about them. What the producer asks is "what is going on in each of
 * my professionals' portals", and that is a list of professionals with their state on it.
 */
class PortalDirectoryTest extends VeterinaryTestCase
{
    public function test_it_lists_every_professional_including_those_holding_no_key(): void
    {
        $response = $this->apiAs('GET', '/veterinary-portal-directory');

        $response->assertStatus(200);

        $rows = $response->json('data');
        $this->assertNotEmpty($rows, 'El seeder deja profesionales cargados.');

        $expected = Veterinarian::where('company_id', $this->company->id)
            ->where('is_active', true)
            ->count();

        $this->assertCount($expected, $rows);

        // A professional with no temporary grant is exactly the case the token-centric screen
        // could not show, so it is the one worth asserting.
        $withoutKey = array_filter($rows, static fn (array $row): bool => $row['active_token_count'] === 0);
        $this->assertNotEmpty($withoutKey, 'Un profesional sin llave vigente tiene que figurar igual.');
    }

    public function test_each_row_says_whether_the_portal_has_an_account(): void
    {
        $vet = $this->veterinarian('MP 4582');

        $row = $this->rowFor((int) $vet->id);

        // ADR-33: creating a professional creates their account, so this is the norm and its
        // absence is what the screen has to make visible.
        $this->assertTrue($row['has_portal_account']);
        $this->assertSame($vet->user_id, $row['user_id']);
        $this->assertSame($vet->email, $row['email']);
    }

    public function test_it_counts_what_is_waiting_in_each_portal(): void
    {
        $vet = $this->veterinarian('MP 4582');
        $vetUser = User::where('email', 'faranguren@ganadero.com')->firstOrFail();

        $before = $this->rowFor((int) $vet->id);

        $actId = $this->emitActFor((int) $vet->id);

        $awaitingSignature = $this->rowFor((int) $vet->id);
        $this->assertSame(
            $before['pending_signature_count'] + 1,
            $awaitingSignature['pending_signature_count']
        );

        $this->apiAsUser($vetUser, 'POST', "/veterinary-portal/acts/{$actId}/sign", [])
            ->assertStatus(200);

        $awaitingReport = $this->rowFor((int) $vet->id);

        // Signing moves the act from one column to the other rather than clearing it: a signed act
        // with no result is still work the portal owes.
        $this->assertSame($before['pending_signature_count'], $awaitingReport['pending_signature_count']);
        $this->assertSame(
            $before['pending_lab_report_count'] + 1,
            $awaitingReport['pending_lab_report_count']
        );

        // ADR-30: the tubes are counted from the tubes, and they are in his hands until dispatched.
        $this->assertGreaterThan($before['unshipped_samples_count'], $awaitingReport['unshipped_samples_count']);
    }

    public function test_a_live_key_shows_up_with_its_expiry(): void
    {
        $vet = $this->veterinarian('MP 4582');

        $this->assertSame(0, $this->rowFor((int) $vet->id)['active_token_count']);

        $this->apiAs('POST', '/veterinary-portal-tokens', [
            'veterinarian_id' => $vet->id,
            'label' => 'Inspección',
            'ttl_hours' => 72,
        ])->assertStatus(201);

        $row = $this->rowFor((int) $vet->id);

        $this->assertSame(1, $row['active_token_count']);
        $this->assertNotNull($row['token_expires_at']);
    }

    public function test_a_revoked_key_stops_counting(): void
    {
        $vet = $this->veterinarian('MP 4582');

        $tokenId = (int) $this->apiAs('POST', '/veterinary-portal-tokens', [
            'veterinarian_id' => $vet->id,
            'ttl_hours' => 72,
        ])->json('data.id');

        $this->apiAs('DELETE', "/veterinary-portal-tokens/{$tokenId}")->assertStatus(200);

        $this->assertSame(0, $this->rowFor((int) $vet->id)['active_token_count']);
    }

    public function test_a_voided_act_stops_being_pending_work(): void
    {
        $vet = $this->veterinarian('MP 4582');

        $before = $this->rowFor((int) $vet->id);
        $actId = $this->emitActFor((int) $vet->id);

        $this->assertSame(
            $before['pending_signature_count'] + 1,
            $this->rowFor((int) $vet->id)['pending_signature_count']
        );

        $this->apiAs('POST', "/diagnostic-protocols/{$actId}/void", [
            'reason' => 'Acta emitida por error durante una prueba.',
        ])->assertStatus(200);

        $this->assertSame(
            $before['pending_signature_count'],
            $this->rowFor((int) $vet->id)['pending_signature_count'],
            'Un acta anulada no es trabajo pendiente de nadie.'
        );
    }

    public function test_tubes_in_hand_counts_glass_and_not_determinations(): void
    {
        // A preputial scrape is one tube cultured for both venereal agents, so it lands in
        // `bull_lab_samples` as two rows. Counting rows told the producer a professional was
        // holding twice the glass they actually had.
        $vet = $this->veterinarian('MP 4582');
        $vetUser = User::where('email', 'faranguren@ganadero.com')->firstOrFail();

        $before = $this->rowFor((int) $vet->id)['unshipped_samples_count'];

        $bull = $this->bulls(1)[0];
        $response = $this->apiAs('POST', '/pre-service/evaluation-sheets', [
            'veterinarian_id' => $vet->id,
            'evaluation_date' => now()->toDateString(),
            'sample_round' => 1,
            'bulls' => [[
                'caravan_id' => $bull->id,
                'prepuce_scrape' => true,
                'prepuce_scrape_tube' => 'R-' . $bull->id . '-' . uniqid(),
            ]],
        ]);
        $response->assertStatus(201);
        $actId = (int) $response->json('data.id');

        $this->apiAsUser($vetUser, 'POST', "/veterinary-portal/acts/{$actId}/sign", [])
            ->assertStatus(200);

        // Two determinations were written; exactly one tube left the chute.
        $this->assertSame(2, DB::table('bull_lab_samples')->where('extraction_act_id', $actId)->count());
        $this->assertSame(
            $before + 1,
            $this->rowFor((int) $vet->id)['unshipped_samples_count'],
            'Un raspaje es un tubo, no dos.'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function rowFor(int $veterinarianId): array
    {
        $rows = $this->apiAs('GET', '/veterinary-portal-directory')->json('data');

        foreach ($rows as $row) {
            if ((int) $row['veterinarian_id'] === $veterinarianId) {
                return $row;
            }
        }

        $this->fail("El profesional {$veterinarianId} no figura en el directorio.");
    }

    private function emitActFor(int $veterinarianId): int
    {
        $bulls = $this->bulls(2);

        $response = $this->apiAs('POST', '/pre-service/evaluation-sheets', [
            'veterinarian_id' => $veterinarianId,
            'evaluation_date' => now()->toDateString(),
            'sample_round' => 1,
            'bulls' => array_map(static fn ($bull): array => [
                'caravan_id' => $bull->id,
                'blood_serology' => true,
                'blood_serology_tube' => 'S-' . $bull->id . '-' . uniqid(),
            ], $bulls),
        ]);

        $response->assertStatus(201);

        return (int) $response->json('data.id');
    }
}
