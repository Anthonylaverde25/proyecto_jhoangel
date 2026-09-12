<?php

declare(strict_types=1);

namespace Tests\Feature\Veterinary;

use App\Core\Enums\AnimalSex;
use App\Models\BullLabSample;
use App\Models\Caravan;
use App\Models\DiagnosticProtocol;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * The failure paths that actually hurt in the field: a report submitted twice because the
 * first attempt gave no feedback, a female tag typed into the bull grid, and evidence left
 * orphaned on disk after a failed ingestion.
 */
class DiagnosticProtocolFailureCasesTest extends VeterinaryTestCase
{
    public function test_resubmitting_the_same_protocol_number_is_rejected_without_duplicating_results(): void
    {
        Storage::fake('tenant');

        $bull = $this->bulls(1)[0];
        $payload = $this->payloadFor($bull->id, 'LAB-2026-DUP-001');

        $this->postProtocol($payload)->assertStatus(201);

        $before = BullLabSample::withoutGlobalScopes()->count();

        $second = $this->postProtocol($this->payloadFor($bull->id, 'LAB-2026-DUP-001'));
        $second->assertStatus(422);

        $this->assertSame(1, DiagnosticProtocol::withoutGlobalScopes()
            ->where('protocol_number', 'LAB-2026-DUP-001')
            ->count());

        $this->assertSame($before, BullLabSample::withoutGlobalScopes()->count());
    }

    public function test_a_female_caravan_is_rejected_from_a_bull_protocol(): void
    {
        Storage::fake('tenant');

        $female = Caravan::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('sex', AnimalSex::FEMALE->value)
            ->first();

        if ($female === null) {
            $female = Caravan::create([
                'company_id' => $this->company->id,
                'identification' => 'VACA-TEST-01',
                'sex' => AnimalSex::FEMALE->value,
            ]);
        }

        $this->postProtocol($this->payloadFor((int) $female->id, 'LAB-2026-FEM-001'))
            ->assertStatus(422);

        $this->assertSame(0, DiagnosticProtocol::withoutGlobalScopes()
            ->where('protocol_number', 'LAB-2026-FEM-001')
            ->count());
    }

    /**
     * ADR-10: a failed ingestion must leave nothing behind. The rejection happens before the
     * transaction opens, so no evidence file may reach the tenant disk either.
     */
    public function test_a_rejected_ingestion_leaves_no_orphan_evidence_on_disk(): void
    {
        Storage::fake('tenant');

        $female = Caravan::create([
            'company_id' => $this->company->id,
            'identification' => 'VACA-TEST-ROLLBACK',
            'sex' => AnimalSex::FEMALE->value,
        ]);

        $this->postProtocol($this->payloadFor((int) $female->id, 'LAB-2026-ROLLBACK'))
            ->assertStatus(422);

        $this->assertSame([], Storage::disk('tenant')->allFiles());
    }

    public function test_digitized_evidence_is_mandatory_for_the_owner_channel(): void
    {
        Storage::fake('tenant');

        $bull = $this->bulls(1)[0];
        $payload = $this->payloadFor($bull->id, 'LAB-2026-NOEVID');
        unset($payload['attachments']);

        $this->postProtocol($payload)->assertStatus(422);
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadFor(int $caravanId, string $protocolNumber): array
    {
        return [
            'protocol_number' => $protocolNumber,
            'sample_date' => now()->subDays(6)->toDateString(),
            'result_date' => now()->subDay()->toDateString(),
            'source_channel' => 'OWNER_DIGITIZED',
            'samples' => json_encode([
                [
                    'caravan_id' => $caravanId,
                    'pathogen_id' => $this->pathogenId('TRITRICHOMONAS_FOETUS'),
                    'sample_type' => 'PREPUCE_SCRAPE',
                    'sample_round' => 4,
                    'status' => 'NEGATIVE_CLEARED',
                ],
            ]),
            'attachments' => [UploadedFile::fake()->image('evidencia.jpg')],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return \Illuminate\Testing\TestResponse
     */
    private function postProtocol(array $payload)
    {
        return $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-ID', (string) $this->company->id)
            ->post($this->url('/diagnostic-protocols'), $payload, ['Accept' => 'application/json']);
    }
}
