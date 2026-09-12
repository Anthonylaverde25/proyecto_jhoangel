<?php

declare(strict_types=1);

namespace Tests\Feature\Veterinary;

use App\Models\BullHealthEvaluation;
use App\Models\DiagnosticProtocol;
use Illuminate\Support\Facades\DB;

/**
 * The chain of custody end to end: the chute opens an act, the professional signs it, the
 * laboratory reports on it — and nothing clears a bull until the signature exists (ADR-17).
 */
class ExtractionActLifecycleTest extends VeterinaryTestCase
{
    public function test_the_chute_sheet_emits_an_unsigned_act_with_a_generated_number(): void
    {
        $bulls = $this->bulls(2);
        $vet = $this->veterinarian('MP 4582');

        $response = $this->apiAs('POST', '/pre-service/evaluation-sheets', [
            'veterinarian_id' => $vet->id,
            'evaluation_date' => now()->toDateString(),
            'sample_round' => 1,
            'bulls' => array_map(static fn ($bull): array => [
                'caravan_id' => $bull->id,
                'scrotal_circumference_cm' => 34,
                'body_condition_score' => 3.5,
                'libido' => 'ALTA',
                'prepuce_scrape' => true,
                'prepuce_scrape_tube' => 'R-' . $bull->id,
            ], $bulls),
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.protocol_type', 'EXTRACTION_ACT');
        $response->assertJsonPath('data.status', 'DRAFT');
        $response->assertJsonPath('data.verification_status', 'UNVERIFIED');
        $response->assertJsonPath('data.is_signed', false);

        // ADR-12: minted by the system, never typed by an operator.
        $this->assertMatchesRegularExpression(
            '/^ACTA-\d{4}-\d{4}$/',
            (string) $response->json('data.protocol_number')
        );

        // ADR-11: no result date, because the laboratory has not spoken yet.
        $this->assertNull($response->json('data.result_date'));

        $actId = (int) $response->json('data.id');

        $this->assertSame(
            4, // two bulls x two venereal agents ruled out by one preputial scrape
            DB::table('bull_lab_samples')->where('extraction_act_id', $actId)->count()
        );
    }

    public function test_the_producer_screen_cannot_forge_a_signature(): void
    {
        $bulls = $this->bulls(1);
        $vet = $this->veterinarian('MP 4582');

        $this->apiAs('POST', '/pre-service/evaluation-sheets', [
            'veterinarian_id' => $vet->id,
            'evaluation_date' => now()->toDateString(),
            'sample_round' => 1,
            // A hostile client trying to sign on the professional's behalf.
            'signed_at' => now()->toDateTimeString(),
            'signed_license_number' => 'MP 9999',
            'bulls' => [[
                'caravan_id' => $bulls[0]->id,
                'scrotal_circumference_cm' => 33,
                'prepuce_scrape' => false,
                'blood_serology' => false,
            ]],
        ])->assertStatus(201);

        $act = DiagnosticProtocol::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('protocol_type', 'EXTRACTION_ACT')
            ->latest('id')
            ->firstOrFail();

        $this->assertNull($act->signed_at);
        $this->assertNull($act->signed_license_number);
        $this->assertNull($act->signed_veterinarian_name);
    }

    public function test_a_drawn_tube_must_carry_its_label(): void
    {
        $bulls = $this->bulls(1);
        $vet = $this->veterinarian('MP 4582');

        $this->apiAs('POST', '/pre-service/evaluation-sheets', [
            'veterinarian_id' => $vet->id,
            'evaluation_date' => now()->toDateString(),
            'sample_round' => 1,
            'bulls' => [[
                'caravan_id' => $bulls[0]->id,
                'prepuce_scrape' => true,
                // tube number deliberately missing
            ]],
        ])->assertStatus(422);
    }

    public function test_unsigned_evidence_never_clears_a_bull(): void
    {
        $bull = $this->bulls(1)[0];
        $vet = $this->veterinarian('MP 4582');

        // Two negative rounds on distinct dates would normally satisfy ADR-4 outright.
        $actId = $this->seedActWithNegativeRounds($bull->id, (int) $vet->id, signed: false);

        $this->recompute([$bull->id]);
        $this->assertNotSame('APT', $this->latestAptitude((int) $bull->id));

        // The very same determinations, once the act behind them is signed.
        DiagnosticProtocol::withoutGlobalScopes()->where('id', $actId)->update([
            'status' => 'CONFIRMED',
            'verification_status' => 'VERIFIED',
            'signed_at' => now(),
            'signed_by_veterinarian_id' => $vet->id,
            'signed_license_number' => $vet->license_number,
            'signed_veterinarian_name' => $vet->name,
        ]);

        $this->recompute([$bull->id]);
        $this->assertSame('APT', $this->latestAptitude((int) $bull->id));
    }

    /**
     * Builds one signed-or-not act carrying two negative rounds for both venereal agents.
     */
    private function seedActWithNegativeRounds(int $caravanId, int $veterinarianId, bool $signed): int
    {
        $act = DiagnosticProtocol::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'protocol_number' => 'ACTA-TEST-' . uniqid(),
            'protocol_type' => 'EXTRACTION_ACT',
            'veterinarian_id' => $veterinarianId,
            'sample_date' => now()->subDays(40)->toDateString(),
            'result_date' => null,
            'source_channel' => $signed ? 'PORTAL_VET' : 'OWNER_DIGITIZED',
            'status' => $signed ? 'CONFIRMED' : 'DRAFT',
            'verification_status' => $signed ? 'VERIFIED' : 'UNVERIFIED',
            'signed_at' => $signed ? now() : null,
        ]);

        BullHealthEvaluation::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'caravan_id' => $caravanId,
            'last_evaluation_date' => now()->subDays(40)->toDateString(),
            'scrotal_circumference_cm' => 36,
            'body_condition_score' => 3.5,
            'libido' => 'ALTA',
            'aplomo_notes' => 'Aplomos correctos.',
            'status' => 'PENDING_EVALUATION',
        ]);

        foreach ([1, 2] as $round) {
            foreach (['TRITRICHOMONAS_FOETUS', 'CAMPYLOBACTER_FETUS'] as $code) {
                DB::table('bull_lab_samples')->insert([
                    'company_id' => $this->company->id,
                    'caravan_id' => $caravanId,
                    'extraction_act_id' => $act->id,
                    'diagnostic_protocol_id' => null,
                    'veterinarian_id' => $veterinarianId,
                    'sample_type' => 'PREPUCE_SCRAPE',
                    'sample_round' => $round,
                    'sample_date' => now()->subDays(40 - $round * 5)->toDateString(),
                    // ADR-26: the tube carries its own chute day, which is what ADR-4 compares.
                    'extracted_on' => now()->subDays(40 - $round * 5)->toDateString(),
                    'tube_number' => "T-{$round}-{$code}",
                    'status' => 'NEGATIVE_CLEARED',
                    'result_date' => now()->subDays(35 - $round * 5)->toDateString(),
                    'pathogen_id' => $this->pathogenId($code),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        return (int) $act->id;
    }

    /**
     * @param list<int> $caravanIds
     */
    private function recompute(array $caravanIds): void
    {
        app(\App\Application\Services\RecomputeBullAptitudeBulkService::class)
            ->__invoke($caravanIds, (int) $this->company->id);
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
