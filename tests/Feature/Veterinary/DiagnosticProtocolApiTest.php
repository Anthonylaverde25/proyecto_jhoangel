<?php

declare(strict_types=1);

namespace Tests\Feature\Veterinary;

use App\Models\BullLabSample;
use App\Models\DiagnosticProtocol;
use App\Models\ProtocolAttachment;
use App\Models\VeterinaryDiagnosis;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Use Case 2 happy path: the producer digitises a laboratory report received over WhatsApp.
 */
class DiagnosticProtocolApiTest extends VeterinaryTestCase
{
    public function test_can_ingest_a_digitized_protocol_with_evidence_and_results(): void
    {
        Storage::fake('tenant');

        $bulls = $this->bulls(15);
        $this->assertCount(15, $bulls);

        $trichomonas = $this->pathogenId('TRITRICHOMONAS_FOETUS');
        $campylobacter = $this->pathogenId('CAMPYLOBACTER_FETUS');
        $vet = $this->veterinarian('MP 6710');

        $samples = [];

        foreach ($bulls as $index => $bull) {
            foreach ([$trichomonas, $campylobacter] as $pathogenId) {
                $samples[] = [
                    'caravan_id' => $bull->id,
                    'pathogen_id' => $pathogenId,
                    'sample_type' => 'PREPUCE_SCRAPE',
                    'sample_round' => 2,
                    // One exception in the troop: the fourth bull came back positive.
                    'status' => $index === 3 && $pathogenId === $trichomonas ? 'POSITIVE_DETECTED' : 'NEGATIVE_CLEARED',
                    'tube_number' => 'R-2-' . ($index + 1),
                ];
            }
        }

        $response = $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-ID', (string) $this->company->id)
            ->post($this->url('/diagnostic-protocols'), [
                'protocol_number' => 'LAB-2026-TEST-001',
                'veterinarian_id' => $vet->id,
                'sample_date' => now()->subDays(8)->toDateString(),
                'result_date' => now()->subDays(2)->toDateString(),
                'source_channel' => 'OWNER_DIGITIZED',
                'observations' => 'Informe remitido por WhatsApp.',
                'samples' => json_encode($samples),
                'attachments' => [
                    UploadedFile::fake()->image('informe_whatsapp.jpg'),
                ],
            ], ['Accept' => 'application/json']);

        $response->assertStatus(201);
        $response->assertJsonPath('data.protocol_number', 'LAB-2026-TEST-001');
        $response->assertJsonPath('data.status', 'CONFIRMED');
        // ADR-9: a hand transcribed report must not weigh the same as one signed in the portal.
        $response->assertJsonPath('data.verification_status', 'UNVERIFIED');
        $response->assertJsonPath('data.samples_count', 30);

        $protocol = DiagnosticProtocol::withoutGlobalScopes()
            ->where('protocol_number', 'LAB-2026-TEST-001')
            ->firstOrFail();

        $this->assertSame(30, BullLabSample::withoutGlobalScopes()
            ->where('diagnostic_protocol_id', $protocol->id)
            ->count());

        // ADR-1 derivation rule: only the positive determination becomes a clinical finding.
        $this->assertSame(1, VeterinaryDiagnosis::withoutGlobalScopes()
            ->where('diagnostic_protocol_id', $protocol->id)
            ->where('status', 'CONFIRMED_POSITIVE')
            ->count());

        $attachment = ProtocolAttachment::withoutGlobalScopes()
            ->where('diagnostic_protocol_id', $protocol->id)
            ->firstOrFail();

        Storage::disk('tenant')->assertExists($attachment->file_path);
        $this->assertSame('informe_whatsapp.jpg', $attachment->file_name);
        $this->assertNotNull($attachment->checksum_sha256);
    }

    public function test_the_positive_bull_is_blocked_and_the_negatives_are_not(): void
    {
        Storage::fake('tenant');

        $bulls = $this->bulls(2);
        $trichomonas = $this->pathogenId('TRITRICHOMONAS_FOETUS');
        $campylobacter = $this->pathogenId('CAMPYLOBACTER_FETUS');

        $samples = [
            ['caravan_id' => $bulls[0]->id, 'pathogen_id' => $trichomonas, 'sample_type' => 'PREPUCE_SCRAPE', 'sample_round' => 3, 'status' => 'POSITIVE_DETECTED'],
            ['caravan_id' => $bulls[1]->id, 'pathogen_id' => $trichomonas, 'sample_type' => 'PREPUCE_SCRAPE', 'sample_round' => 3, 'status' => 'NEGATIVE_CLEARED'],
            ['caravan_id' => $bulls[1]->id, 'pathogen_id' => $campylobacter, 'sample_type' => 'PREPUCE_SCRAPE', 'sample_round' => 3, 'status' => 'NEGATIVE_CLEARED'],
        ];

        $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-ID', (string) $this->company->id)
            ->post($this->url('/diagnostic-protocols'), [
                'protocol_number' => 'LAB-2026-TEST-002',
                'sample_date' => now()->subDays(5)->toDateString(),
                'result_date' => now()->subDay()->toDateString(),
                'source_channel' => 'OWNER_DIGITIZED',
                'samples' => json_encode($samples),
                'attachments' => [UploadedFile::fake()->image('acta.jpg')],
            ], ['Accept' => 'application/json'])
            ->assertStatus(201);

        $this->assertSame('UNFIT', $this->latestAptitude($bulls[0]->id));
        $this->assertNotSame('UNFIT', $this->latestAptitude($bulls[1]->id));
    }

    private function latestAptitude(int $caravanId): string
    {
        return (string) \App\Models\BullHealthEvaluation::withoutGlobalScopes()
            ->where('caravan_id', $caravanId)
            ->orderByDesc('last_evaluation_date')
            ->orderByDesc('id')
            ->firstOrFail()
            ->status;
    }
}
