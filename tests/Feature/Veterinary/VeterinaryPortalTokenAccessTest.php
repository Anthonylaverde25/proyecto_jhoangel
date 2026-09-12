<?php

declare(strict_types=1);

namespace Tests\Feature\Veterinary;

use App\Models\Caravan;
use App\Models\DiagnosticProtocol;
use App\Models\VeterinarianBatchAssignment;
use App\Models\VeterinaryPortalAccessToken;

/**
 * Use Case 1, external door: a professional or health centre with no account in the system
 * reaches the same portal through a temporary link handed out by the producer.
 */
class VeterinaryPortalTokenAccessTest extends VeterinaryTestCase
{
    private const DEMO_TOKEN = 'demo-vet-sosa-token-0000000000000001';

    public function test_a_valid_temporary_link_opens_the_portal_without_any_login(): void
    {
        $response = $this->withHeader('X-Vet-Access-Token', self::DEMO_TOKEN)
            ->getJson($this->url('/veterinary-portal/session'));

        $response->assertStatus(200);
        $response->assertJsonPath('data.access_mode', 'TEMPORARY_TOKEN');
        $response->assertJsonPath('data.veterinarian.license_number', 'MP 6710');
        // The grant, not a client header, is what establishes the tenant company.
        $response->assertJsonPath('data.company_id', $this->company->id);
    }

    public function test_the_workspace_only_exposes_the_troop_covered_by_the_grant(): void
    {
        $response = $this->withHeader('X-Vet-Access-Token', self::DEMO_TOKEN)
            ->getJson($this->url('/veterinary-portal/workspace'));

        $response->assertStatus(200);

        $allowedBatchIds = $this->allowedBatchIdsForDemoToken();
        $batchIds = array_column((array) $response->json('data.batches'), 'id');

        $this->assertNotEmpty($batchIds);
        $this->assertEmpty(array_diff($batchIds, $allowedBatchIds));
    }

    public function test_the_external_professional_can_file_a_signed_protocol(): void
    {
        $batchId = $this->allowedBatchIdsForDemoToken()[0];

        $bull = Caravan::create([
            'company_id' => $this->company->id,
            'identification' => 'TOKEN-TR-01',
            'sex' => 'M',
            'batch_id' => $batchId,
        ]);

        $response = $this->withHeader('X-Vet-Access-Token', self::DEMO_TOKEN)
            ->postJson($this->url('/veterinary-portal/evaluations'), [
                'batch_id' => $batchId,
                'protocol_number' => 'TOKEN-2026-001',
                'sample_date' => now()->subDay()->toDateString(),
                'result_date' => now()->toDateString(),
                'bulls' => [
                    [
                        'caravan_id' => $bull->id,
                        'scrotal_circumference_cm' => 37.0,
                        'body_condition_score' => 3.5,
                        'samples' => [
                            [
                                'pathogen_id' => $this->pathogenId('TRITRICHOMONAS_FOETUS'),
                                'sample_type' => 'PREPUCE_SCRAPE',
                                'sample_round' => 1,
                                'status' => 'NEGATIVE_CLEARED',
                            ],
                        ],
                    ],
                ],
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.signed_license_number', 'MP 6710');

        $protocol = DiagnosticProtocol::withoutGlobalScopes()
            ->where('protocol_number', 'TOKEN-2026-001')
            ->firstOrFail();

        // Nobody was logged in: the report is attributable to the professional, not to a user.
        $this->assertNull($protocol->created_by_user_id);
    }

    public function test_using_the_link_records_the_access_for_audit(): void
    {
        $before = $this->demoToken()->used_count;

        $this->withHeader('X-Vet-Access-Token', self::DEMO_TOKEN)
            ->getJson($this->url('/veterinary-portal/session'))
            ->assertStatus(200);

        $this->assertSame($before + 1, $this->demoToken()->used_count);
    }

    public function test_an_unknown_token_is_rejected(): void
    {
        $this->withHeader('X-Vet-Access-Token', 'no-existe-este-token')
            ->getJson($this->url('/veterinary-portal/session'))
            ->assertStatus(401);
    }

    public function test_a_revoked_link_stops_working_immediately(): void
    {
        $this->demoToken()->update(['revoked_at' => now()]);

        $this->withHeader('X-Vet-Access-Token', self::DEMO_TOKEN)
            ->getJson($this->url('/veterinary-portal/session'))
            ->assertStatus(401);
    }

    public function test_an_expired_link_stops_working(): void
    {
        $this->demoToken()->update(['expires_at' => now()->subMinute()]);

        $this->withHeader('X-Vet-Access-Token', self::DEMO_TOKEN)
            ->getJson($this->url('/veterinary-portal/session'))
            ->assertStatus(401);
    }

    public function test_an_exhausted_link_stops_working(): void
    {
        $this->demoToken()->update(['max_uses' => 1, 'used_count' => 1]);

        $this->withHeader('X-Vet-Access-Token', self::DEMO_TOKEN)
            ->getJson($this->url('/veterinary-portal/session'))
            ->assertStatus(401);
    }

    /**
     * The grant scopes what the holder may touch: a batch outside it is refused even though
     * the link itself is perfectly valid.
     */
    public function test_a_batch_outside_the_grant_is_refused(): void
    {
        $allowed = $this->allowedBatchIdsForDemoToken();

        $foreignBatchId = (int) \App\Models\Batch::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->whereNotIn('id', $allowed)
            ->value('id');

        $this->assertGreaterThan(0, $foreignBatchId, 'El fixture necesita un lote fuera de la asignación.');

        $bull = Caravan::create([
            'company_id' => $this->company->id,
            'identification' => 'TOKEN-TR-FORANEO',
            'sex' => 'M',
            'batch_id' => $foreignBatchId,
        ]);

        $this->withHeader('X-Vet-Access-Token', self::DEMO_TOKEN)
            ->postJson($this->url('/veterinary-portal/evaluations'), [
                'batch_id' => $foreignBatchId,
                'protocol_number' => 'TOKEN-2026-FORANEO',
                'sample_date' => now()->subDay()->toDateString(),
                'result_date' => now()->toDateString(),
                'bulls' => [['caravan_id' => $bull->id, 'scrotal_circumference_cm' => 36.0]],
            ])
            ->assertStatus(422);
    }

    public function test_the_producer_can_issue_and_revoke_a_link(): void
    {
        $vet = $this->veterinarian('MP 3391');

        $issued = $this->apiAs('POST', '/veterinary-portal-tokens', [
            'veterinarian_id' => $vet->id,
            'label' => 'Raspajes lote recría',
            'ttl_hours' => 48,
        ]);

        $issued->assertStatus(201);

        $plainToken = $issued->json('data.plain_token');
        $this->assertIsString($plainToken);
        $this->assertNotEmpty($issued->json('data.access_url'));

        // Only the hash is persisted: a database dump must not yield working links.
        $stored = VeterinaryPortalAccessToken::withoutGlobalScopes()
            ->where('token_hash', hash('sha256', $plainToken))
            ->firstOrFail();

        $this->withHeader('X-Vet-Access-Token', $plainToken)
            ->getJson($this->url('/veterinary-portal/session'))
            ->assertStatus(200);

        $this->apiAs('DELETE', "/veterinary-portal-tokens/{$stored->id}", ['reason' => 'Trabajo finalizado.'])
            ->assertStatus(200);

        $this->withHeader('X-Vet-Access-Token', $plainToken)
            ->getJson($this->url('/veterinary-portal/session'))
            ->assertStatus(401);
    }

    private function demoToken(): VeterinaryPortalAccessToken
    {
        return VeterinaryPortalAccessToken::withoutGlobalScopes()
            ->where('token_hash', hash('sha256', self::DEMO_TOKEN))
            ->firstOrFail();
    }

    /**
     * @return list<int>
     */
    private function allowedBatchIdsForDemoToken(): array
    {
        $token = $this->demoToken();

        if ($token->batch_id !== null) {
            return [(int) $token->batch_id];
        }

        return VeterinarianBatchAssignment::withoutGlobalScopes()
            ->where('veterinarian_id', $token->veterinarian_id)
            ->whereNull('unassigned_at')
            ->pluck('batch_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
