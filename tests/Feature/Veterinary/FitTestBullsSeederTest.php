<?php

declare(strict_types=1);

namespace Tests\Feature\Veterinary;

use App\Models\Batch;
use App\Models\BullHealthEvaluation;
use App\Models\BullLabSample;
use App\Models\Caravan;
use App\Models\VeterinarianBatchAssignment;
use App\Models\VeterinaryDiagnosis;
use Database\Seeders\BullAptitudeRecalculationSeeder;
use Database\Seeders\FitTestBullsSeeder;

/**
 * ANTHONY-TORO-TEST-004..006 are the APT fixture: every reproductive parameter correct and the
 * venereal sampling backed by signed evidence. If a rule change stops them from being APT, this
 * is where it shows, instead of in a manual test that silently starts failing.
 */
class FitTestBullsSeederTest extends VeterinaryTestCase
{
    private const TAGS = [
        'ANTHONY-TORO-TEST-004',
        'ANTHONY-TORO-TEST-005',
        'ANTHONY-TORO-TEST-006',
    ];

    public function test_the_three_bulls_are_apt_under_the_aptitude_engine(): void
    {
        foreach (self::TAGS as $tag) {
            $caravan = $this->testBull($tag);

            $this->assertSame('APT', $this->latestEvaluation($caravan)->status, $tag);
            $this->assertSame(0, VeterinaryDiagnosis::withoutGlobalScopes()->where('caravan_id', $caravan->id)->count(), $tag);
        }
    }

    public function test_each_bull_has_two_negative_rounds_per_venereal_pathogen(): void
    {
        foreach (self::TAGS as $tag) {
            $samples = BullLabSample::withoutGlobalScopes()
                ->where('caravan_id', $this->testBull($tag)->id)
                ->get();

            $this->assertCount(4, $samples, $tag);
            $this->assertTrue($samples->every(fn ($s) => $s->status === 'NEGATIVE_CLEARED'), $tag);
            $this->assertEqualsCanonicalizing([1, 2], $samples->pluck('sample_round')->unique()->values()->all(), $tag);
        }
    }

    public function test_they_live_in_an_own_breeding_batch_reachable_from_the_portal(): void
    {
        $batch = Batch::withoutGlobalScopes()->findOrFail($this->testBull(self::TAGS[0])->batch_id);

        $this->assertNull($batch->farm_id);
        $this->assertSame('CRIA', \App\Models\Activity::withoutGlobalScopes()->find($batch->activity_id)?->code);
        $this->assertTrue(
            VeterinarianBatchAssignment::withoutGlobalScopes()
                ->where('batch_id', $batch->id)
                ->whereNull('unassigned_at')
                ->exists()
        );
    }

    public function test_reseeding_after_a_recompute_keeps_them_apt_without_duplicating_history(): void
    {
        $this->seed(BullAptitudeRecalculationSeeder::class);
        $this->seed(FitTestBullsSeeder::class);

        foreach (self::TAGS as $tag) {
            $caravan = $this->testBull($tag);

            $this->assertSame('APT', $this->latestEvaluation($caravan)->status, $tag);
            $this->assertSame(1, BullHealthEvaluation::withoutGlobalScopes()->where('caravan_id', $caravan->id)->count(), $tag);
            $this->assertSame(4, BullLabSample::withoutGlobalScopes()->where('caravan_id', $caravan->id)->count(), $tag);
        }
    }

    private function latestEvaluation(Caravan $caravan): BullHealthEvaluation
    {
        return BullHealthEvaluation::withoutGlobalScopes()
            ->where('caravan_id', $caravan->id)
            ->orderByDesc('last_evaluation_date')
            ->orderByDesc('id')
            ->firstOrFail();
    }

    private function testBull(string $tag): Caravan
    {
        return Caravan::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('identification', $tag)
            ->firstOrFail();
    }
}
