<?php

declare(strict_types=1);

namespace Tests\Feature\Veterinary;

use App\Models\DiagnosticProtocol;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * ADR-13 / ADR-11 / ADR-15: the professional closes the chain of custody, the laboratory then
 * reports as its own document, and both signatures freeze the institution alongside the licence.
 */
class PortalActSignatureTest extends VeterinaryTestCase
{
    private User $vetUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vetUser = User::where('email', 'faranguren@ganadero.com')->firstOrFail();
    }

    public function test_the_professional_signs_their_act_and_their_identity_is_frozen(): void
    {
        $actId = $this->emitActFromChute();

        $response = $this->asPortalVet('POST', "/veterinary-portal/acts/{$actId}/sign", [
            'observations' => 'Revisado en manga, tubos rotulados en presencia del productor.',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'CONFIRMED');
        $response->assertJsonPath('data.verification_status', 'VERIFIED');
        $response->assertJsonPath('data.is_signed', true);
        $response->assertJsonPath('data.signed_license_number', 'MP 4582');
        // The channel flips: the document is no longer a producer transcription.
        $response->assertJsonPath('data.source_channel', 'PORTAL_VET');

        // ADR-29 / ADR-38: a signature attests a PERSON. No institution is frozen next to it —
        // that is precisely what let a third party laboratory end up stamped under a licence.
        $vet = $this->veterinarian('MP 4582');

        $response->assertJsonPath('data.signed_cuit', $vet->cuit);
        $response->assertJsonPath('data.signed_billing_cuit', $vet->billing_cuit);
    }

    public function test_an_act_cannot_be_signed_twice(): void
    {
        $actId = $this->emitActFromChute();

        $this->asPortalVet('POST', "/veterinary-portal/acts/{$actId}/sign", [])->assertStatus(200);
        $this->asPortalVet('POST', "/veterinary-portal/acts/{$actId}/sign", [])->assertStatus(422);
    }

    public function test_a_lab_report_needs_a_signed_act(): void
    {
        $actId = $this->emitActFromChute();
        $sampleId = (int) DB::table('bull_lab_samples')->where('extraction_act_id', $actId)->value('id');

        // Unsigned: there are no legally identified tubes to report on yet.
        $this->asPortalVet('POST', "/veterinary-portal/acts/{$actId}/lab-report", [
            'lab_report_number' => 'LAB-2026-9001',
            'result_date' => now()->toDateString(),
            'reporting_institution' => ['nombre' => 'Laboratorio Regional Tandil', 'cuit' => '30712345671'],
            'lines' => [['sample_id' => $sampleId, 'status' => 'NEGATIVE_CLEARED']],
        ])->assertStatus(422);

        $this->asPortalVet('POST', "/veterinary-portal/acts/{$actId}/sign", [])->assertStatus(200);


        $response = $this->asPortalVet('POST', "/veterinary-portal/acts/{$actId}/lab-report", [
            'lab_report_number' => 'LAB-2026-9001',
            'result_date' => now()->toDateString(),
            'reporting_institution' => ['nombre' => 'Laboratorio Regional Tandil', 'cuit' => '30712345671'],
            'lines' => [['sample_id' => $sampleId, 'status' => 'NEGATIVE_CLEARED']],
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.protocol_type', 'LAB_REPORT');
        $response->assertJsonPath('data.parent_protocol_id', $actId);
        $response->assertJsonPath('data.protocol_number', 'LAB-2026-9001');

        // The tube was resolved in place; the laboratory never mints a new one.
        $sample = DB::table('bull_lab_samples')->where('id', $sampleId)->first();
        $this->assertSame('NEGATIVE_CLEARED', $sample->status);
        $this->assertSame((int) $response->json('data.id'), (int) $sample->diagnostic_protocol_id);
        $this->assertSame($actId, (int) $sample->extraction_act_id);
    }

    public function test_the_inbox_lists_what_the_professional_owes(): void
    {
        $actId = $this->emitActFromChute();

        $pending = $this->asPortalVet('GET', '/veterinary-portal/acts');
        $pending->assertStatus(200);

        $ids = array_column($pending->json('data.pending_signature') ?? [], 'id');
        $this->assertContains($actId, $ids);

        $this->asPortalVet('POST', "/veterinary-portal/acts/{$actId}/sign", [])->assertStatus(200);

        $afterSigning = $this->asPortalVet('GET', '/veterinary-portal/acts');
        $this->assertNotContains($actId, array_column($afterSigning->json('data.pending_signature') ?? [], 'id'));

        // Signing moves the act straight onto the laboratory axis: with the arrival concept gone
        // there is nothing else to wait for.
        $this->assertContains($actId, array_column($afterSigning->json('data.pending_lab_report') ?? [], 'id'));
    }

    public function test_another_professional_cannot_sign_somebody_elses_act(): void
    {
        // A signature is personal, and v9 removes the only thing that ever blurred that: sharing
        // an institution. Roldán invoices under the same entity as Aranguren (ADR-38) and still
        // cannot attest his work.
        $actId = $this->emitActFromChute();

        $colleague = $this->veterinarian('MP 5127');
        $colleagueUser = User::where('email', 'croldan@ganadero.com')->firstOrFail();

        $this->assertSame(
            $this->veterinarian('MP 4582')->billing_cuit,
            $colleague->billing_cuit,
            'Comparten entidad de facturación: si aun así no puede firmar, la regla es personal.'
        );

        $this->apiAsUser($colleagueUser, 'POST', "/veterinary-portal/acts/{$actId}/sign", [])
            ->assertStatus(422);

        $this->assertNull(
            DiagnosticProtocol::withoutGlobalScopes()->findOrFail($actId)->signed_at
        );
    }


    /**
     * The producer's screen opens the act; it never signs it.
     */
    private function emitActFromChute(): int
    {
        $bulls = $this->bulls(2);
        $vet = $this->veterinarian('MP 4582');

        $response = $this->apiAs('POST', '/pre-service/evaluation-sheets', [
            'veterinarian_id' => $vet->id,
            'evaluation_date' => now()->toDateString(),
            'sample_round' => 1,
            'bulls' => array_map(static fn ($bull): array => [
                'caravan_id' => $bull->id,
                'scrotal_circumference_cm' => 35,
                'prepuce_scrape' => true,
                'prepuce_scrape_tube' => 'R-' . $bull->id,
            ], $bulls),
        ]);

        $response->assertStatus(201);

        return (int) $response->json('data.id');
    }

    /**
     * @return \Illuminate\Testing\TestResponse
     */
    private function asPortalVet(string $method, string $path, array $payload = [])
    {
        return $this->actingAs($this->vetUser, 'sanctum')
            ->withHeader('X-Company-ID', (string) $this->company->id)
            ->json($method, $this->url($path), $payload);
    }
}
