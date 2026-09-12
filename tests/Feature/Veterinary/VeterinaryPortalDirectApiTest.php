<?php

declare(strict_types=1);

namespace Tests\Feature\Veterinary;

use App\Models\BullHealthEvaluation;
use App\Models\Caravan;
use App\Models\DiagnosticProtocol;
use App\Models\User;
use App\Models\VeterinarianBatchAssignment;

/**
 * Use Case 1, internal door: a staff veterinarian with the `veterinarian` role on the
 * `company_user` pivot loads the chute results from inside the system.
 */
class VeterinaryPortalDirectApiTest extends VeterinaryTestCase
{
    private User $vetUser;

    protected function setUp(): void
    {
        parent::setUp();

        // Seeded by VeterinaryCatalogSeeder and already linked to the catalogue row.
        $this->vetUser = User::where('email', 'faranguren@ganadero.com')->firstOrFail();
    }

    public function test_the_portal_session_reports_the_professional_and_the_assigned_troop(): void
    {
        $response = $this->actingAs($this->vetUser, 'sanctum')
            ->withHeader('X-Company-ID', (string) $this->company->id)
            ->getJson($this->url('/veterinary-portal/session'));

        $response->assertStatus(200);
        $response->assertJsonPath('data.access_mode', 'INTERNAL_USER');
        $response->assertJsonPath('data.veterinarian.license_number', 'MP 4582');
        $this->assertNotEmpty($response->json('data.allowed_batch_ids'));
    }

    public function test_a_single_negative_round_leaves_the_bull_pending_not_apt(): void
    {
        $vet = $this->veterinarian('MP 4582');
        $batchId = (int) VeterinarianBatchAssignment::withoutGlobalScopes()
            ->where('veterinarian_id', $vet->id)
            ->whereNull('unassigned_at')
            ->value('batch_id');

        $bull = $this->freshBullInBatch($batchId, 'PORTAL-TR-01');

        $response = $this->actingAs($this->vetUser, 'sanctum')
            ->withHeader('X-Company-ID', (string) $this->company->id)
            ->postJson($this->url('/veterinary-portal/evaluations'), [
                'batch_id' => $batchId,
                'protocol_number' => 'MANGA-2026-001',
                'sample_date' => now()->subDay()->toDateString(),
                'result_date' => now()->toDateString(),
                'bulls' => [
                    [
                        'caravan_id' => $bull->id,
                        'scrotal_circumference_cm' => 36.0,
                        'body_condition_score' => 3.5,
                        'aplomo_notes' => 'Aplomos correctos.',
                        'libido' => 'ALTA',
                        'samples' => [
                            [
                                'pathogen_id' => $this->pathogenId('TRITRICHOMONAS_FOETUS'),
                                'sample_type' => 'PREPUCE_SCRAPE',
                                'sample_round' => 1,
                                'status' => 'NEGATIVE_CLEARED',
                            ],
                            [
                                'pathogen_id' => $this->pathogenId('CAMPYLOBACTER_FETUS'),
                                'sample_type' => 'PREPUCE_SCRAPE',
                                'sample_round' => 1,
                                'status' => 'NEGATIVE_CLEARED',
                            ],
                        ],
                    ],
                ],
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.source_channel', 'PORTAL_VET');
        $response->assertJsonPath('data.verification_status', 'VERIFIED');
        // ADR-8: the signature is frozen onto the protocol, not joined live from the catalogue.
        $response->assertJsonPath('data.signed_license_number', 'MP 4582');
        $response->assertJsonPath('data.is_signed', true);

        // ADR-4: one negative round of the two required is not a certification.
        $this->assertSame('PENDING_EVALUATION', $this->latestAptitude((int) $bull->id));
    }

    public function test_editing_the_catalogue_does_not_rewrite_a_signed_protocol(): void
    {
        $vet = $this->veterinarian('MP 4582');
        $batchId = (int) VeterinarianBatchAssignment::withoutGlobalScopes()
            ->where('veterinarian_id', $vet->id)
            ->whereNull('unassigned_at')
            ->value('batch_id');

        $bull = $this->freshBullInBatch($batchId, 'PORTAL-TR-02');

        $this->actingAs($this->vetUser, 'sanctum')
            ->withHeader('X-Company-ID', (string) $this->company->id)
            ->postJson($this->url('/veterinary-portal/evaluations'), [
                'batch_id' => $batchId,
                'protocol_number' => 'MANGA-2026-002',
                'sample_date' => now()->subDay()->toDateString(),
                'result_date' => now()->toDateString(),
                'bulls' => [['caravan_id' => $bull->id, 'scrotal_circumference_cm' => 35.0]],
            ])
            ->assertStatus(201);

        $this->apiAs('PATCH', "/veterinarians/{$vet->id}", [
            'name' => 'Dr. Fernando Aranguren',
            'license_number' => 'MP 9999',
        ])->assertStatus(200);

        $protocol = DiagnosticProtocol::withoutGlobalScopes()
            ->where('protocol_number', 'MANGA-2026-002')
            ->firstOrFail();

        $this->assertSame('MP 4582', $protocol->signed_license_number);
    }

    private function freshBullInBatch(int $batchId, string $identification): Caravan
    {
        return Caravan::create([
            'company_id' => $this->company->id,
            'identification' => $identification,
            'sex' => 'M',
            'batch_id' => $batchId,
        ]);
    }

    private function latestAptitude(int $caravanId): string
    {
        return (string) BullHealthEvaluation::withoutGlobalScopes()
            ->where('caravan_id', $caravanId)
            ->orderByDesc('last_evaluation_date')
            ->orderByDesc('id')
            ->firstOrFail()
            ->status;
    }
}
