<?php

declare(strict_types=1);

namespace Tests\Feature\Veterinary;

use App\Models\BullHealthEvaluation;
use App\Models\DiagnosticProtocol;
use App\Models\VeterinaryDiagnosis;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Use Case 3 (ADR-9). Without this flow, a typing error over a phone photo is permanent and
 * can block or enable a bull for a reason that never existed.
 */
class DiagnosticProtocolVoidTest extends VeterinaryTestCase
{
    public function test_voiding_a_protocol_reverts_its_findings_and_recomputes_aptitude(): void
    {
        Storage::fake('tenant');

        $bull = $this->bulls(1)[0];

        $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-ID', (string) $this->company->id)
            ->post($this->url('/diagnostic-protocols'), [
                'protocol_number' => 'LAB-2026-VOID-001',
                'sample_date' => now()->subDays(5)->toDateString(),
                'result_date' => now()->subDay()->toDateString(),
                'source_channel' => 'OWNER_DIGITIZED',
                'samples' => json_encode([
                    [
                        'caravan_id' => $bull->id,
                        'pathogen_id' => $this->pathogenId('TRITRICHOMONAS_FOETUS'),
                        'sample_type' => 'PREPUCE_SCRAPE',
                        'sample_round' => 5,
                        'status' => 'POSITIVE_DETECTED',
                    ],
                ]),
                'attachments' => [UploadedFile::fake()->image('acta_erronea.jpg')],
            ], ['Accept' => 'application/json'])
            ->assertStatus(201);

        $protocol = DiagnosticProtocol::withoutGlobalScopes()
            ->where('protocol_number', 'LAB-2026-VOID-001')
            ->firstOrFail();

        $this->assertSame('UNFIT', $this->latestAptitude((int) $bull->id));

        $response = $this->apiAs('POST', "/diagnostic-protocols/{$protocol->id}/void", [
            'reason' => 'Caravana equivocada: el positivo correspondía a otro reproductor.',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'VOIDED');

        // The derived finding is resolved rather than deleted: the history must show that
        // something was recorded, and why it stopped counting.
        $finding = VeterinaryDiagnosis::withoutGlobalScopes()
            ->where('diagnostic_protocol_id', $protocol->id)
            ->firstOrFail();

        $this->assertSame('RESOLVED', $finding->status);
        $this->assertStringContainsString('ANULADO', (string) $finding->treatment_notes);

        // The bull is no longer disqualified by a report that no longer counts.
        $this->assertNotSame('UNFIT', $this->latestAptitude((int) $bull->id));
    }

    public function test_a_void_without_a_reason_is_rejected(): void
    {
        $protocol = DiagnosticProtocol::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('status', 'CONFIRMED')
            ->firstOrFail();

        $this->apiAs('POST', "/diagnostic-protocols/{$protocol->id}/void", ['reason' => ''])
            ->assertStatus(422);
    }

    public function test_a_protocol_cannot_be_voided_twice(): void
    {
        $protocol = DiagnosticProtocol::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('status', 'VOIDED')
            ->firstOrFail();

        $this->apiAs('POST', "/diagnostic-protocols/{$protocol->id}/void", [
            'reason' => 'Intento de doble anulación.',
        ])->assertStatus(422);
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
