<?php

declare(strict_types=1);

namespace Tests\Feature\Veterinary;

use App\Application\Services\RecomputeBullAptitudeBulkService;
use App\Models\BullHealthEvaluation;
use App\Models\DiagnosticProtocol;
use Illuminate\Support\Facades\DB;

/**
 * ADR-17: only signed evidence clears a bull, and the rule stays deliberately asymmetric.
 *
 * v9 removed the arrival axis: a tube processed in the professional's own laboratory never
 * travels anywhere, so demanding a declared arrival asked for a fact nobody witnessed.
 */
class AptitudeTest extends VeterinaryTestCase
{

    public function test_biometry_counts_with_signature_alone(): void
    {
        // Case 5: the tubes never arrive, and the chute measurements still stand on their own.
        // A bull disqualified by scrotal circumference does not need a laboratory to say so.
        $bull = $this->bulls(1)[0];
        $vet = $this->veterinarian('MP 4582');

        $actId = $this->seedSignedActWithTwoNegativeRounds((int) $bull->id, (int) $vet->id, scrotalCircumference: 24.0);

        // No laboratory ever spoke about these tubes.
        DB::table('bull_lab_samples')->where('extraction_act_id', $actId)->update([
            'status' => 'PENDING_RESULTS',
            'result_date' => null,
        ]);

        $this->recompute([(int) $bull->id]);

        $this->assertSame('UNFIT', $this->latestAptitude((int) $bull->id));
    }

    public function test_negative_rounds_compare_tube_dates_not_act_date(): void
    {
        // ADR-26 / Case 9: one act opened long ago and worked over two jornadas. Read from the
        // act, every tube would look as old as the document and fall outside the validity window
        // — a false negative of clearance produced by a column, not by the animal.
        $bull = $this->bulls(1)[0];
        $vet = $this->veterinarian('MP 4582');

        $actOpenedOn = now()->subDays(400)->toDateString();
        $actId = $this->seedSignedActWithTwoNegativeRounds(
            (int) $bull->id,
            (int) $vet->id,
            actDate: $actOpenedOn
        );

        // Both tubes carry the act's stale date in the legacy column and their real chute day in
        // the one ADR-26 introduced.
        DB::table('bull_lab_samples')->where('extraction_act_id', $actId)->update([
            'sample_date' => $actOpenedOn,
        ]);
        DB::table('bull_lab_samples')->where('extraction_act_id', $actId)->where('sample_round', 1)
            ->update(['extracted_on' => now()->subDays(20)->toDateString()]);
        DB::table('bull_lab_samples')->where('extraction_act_id', $actId)->where('sample_round', 2)
            ->update(['extracted_on' => now()->subDays(10)->toDateString()]);

        $this->recompute([(int) $bull->id]);
        $this->assertSame('APT', $this->latestAptitude((int) $bull->id));

        // Drop the per-tube date and the same evidence stops clearing him: the act's opening day
        // is 400 days old and the validity window is a year.
        DB::table('bull_lab_samples')->where('extraction_act_id', $actId)->update(['extracted_on' => null]);

        $this->recompute([(int) $bull->id]);
        $this->assertNotSame('APT', $this->latestAptitude((int) $bull->id));
    }

    /**
     * A signed act whose tubes all arrived, carrying two negative rounds for both venereal
     * agents — the shape that satisfies ADR-4 outright.
     */
    private function seedSignedActWithTwoNegativeRounds(
        int $caravanId,
        int $veterinarianId,
        float $scrotalCircumference = 36.0,
        ?string $actDate = null
    ): int {
        $actDate ??= now()->subDays(40)->toDateString();

        $act = DiagnosticProtocol::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'protocol_number' => 'ACTA-APT-' . uniqid(),
            'protocol_type' => 'EXTRACTION_ACT',
            'veterinarian_id' => $veterinarianId,
            'sample_date' => $actDate,
            'result_date' => null,
            'source_channel' => 'PORTAL_VET',
            'status' => 'CONFIRMED',
            'verification_status' => 'VERIFIED',
            'signed_at' => now(),
            'signed_by_veterinarian_id' => $veterinarianId,
        ]);

        BullHealthEvaluation::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'caravan_id' => $caravanId,
            'last_evaluation_date' => $actDate,
            'scrotal_circumference_cm' => $scrotalCircumference,
            'body_condition_score' => 3.5,
            'libido' => 'ALTA',
            'aplomo_notes' => 'Aplomos correctos.',
            'status' => 'PENDING_EVALUATION',
        ]);

        foreach ([1, 2] as $round) {
            foreach (['TRITRICHOMONAS_FOETUS', 'CAMPYLOBACTER_FETUS'] as $code) {
                $drawnOn = now()->subDays(30 - $round * 5)->toDateString();

                DB::table('bull_lab_samples')->insert([
                    'company_id' => $this->company->id,
                    'caravan_id' => $caravanId,
                    'extraction_act_id' => $act->id,
                    'diagnostic_protocol_id' => null,
                    'veterinarian_id' => $veterinarianId,
                    'sample_type' => 'PREPUCE_SCRAPE',
                    'sample_round' => $round,
                    'sample_date' => $drawnOn,
                    'extracted_on' => $drawnOn,
                    'tube_number' => "T-{$round}-{$code}",
                    'status' => 'NEGATIVE_CLEARED',
                    'result_date' => now()->subDays(25 - $round * 5)->toDateString(),
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
        app(RecomputeBullAptitudeBulkService::class)->__invoke($caravanIds, (int) $this->company->id);
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
