<?php

declare(strict_types=1);

namespace Tests\Feature\Veterinary;

use App\Models\Activity;
use App\Models\Batch;
use App\Models\Caravan;
use App\Models\Pathogen;

/**
 * A bull found positive to a contagious pathogen raises a non-blocking alert listing the
 * females that served with him, to be followed at the pregnancy check.
 *
 * ANTHONY-TORO-TEST-004..006 are seeded APT.
 */
class SanitaryExposureAlertTest extends VeterinaryTestCase
{
    private Batch $rodeo;
    private Batch $bullPaddock;

    protected function setUp(): void
    {
        parent::setUp();

        $cria = Activity::updateOrCreate(['code' => 'CRIA'], ['name' => 'Cría']);
        $this->rodeo = $this->batch('Rodeo Exposición', $cria->id);
        $this->bullPaddock = $this->batch('Torada Exposición', $cria->id);
    }

    public function test_a_venereal_positive_lists_the_females_that_served_with_the_bull(): void
    {
        $cows = [$this->cow('EXP-V-01'), $this->cow('EXP-V-02')];
        $bull = $this->bullIn('ANTHONY-TORO-TEST-004');
        $orderId = $this->startService([$bull->id]);

        $this->diagnose($bull, 'TRITRICHOMONAS_FOETUS', '2026-12-01')->assertStatus(201);

        $alerts = $this->alertsOf($bull, 'OPEN');
        $this->assertCount(1, $alerts);
        $alert = $alerts[0];
        $this->assertSame('ANTHONY-TORO-TEST-004', $alert['bull']['identification']);
        $this->assertSame('TRITRICHOMONAS_FOETUS', $alert['pathogen_code']);
        $this->assertSame('2026-12-01', $alert['detected_on']);
        $this->assertSame(2, $alert['females_count']);
        $this->assertSame($orderId, $alert['bull_active_order']['service_order_id']);
        $this->assertStringContainsString('2 vientres estuvieron', $alert['detail']);

        $detail = $this->apiAs('GET', "/sanitary-exposure-alerts/{$alert['id']}")->assertStatus(200)->json();
        $this->assertEqualsCanonicalizing(['EXP-V-01', 'EXP-V-02'], array_column($detail['females'], 'identification'));
        $this->assertSame('2026-11-01', $detail['females'][0]['overlap_from']);
        $this->assertNull($detail['females'][0]['overlap_until']);

        // The batch shows it, and the pregnancy check grid can mark the cows.
        $overview = $this->apiAs('GET', "/batches/{$this->rodeo->id}/overview")->assertStatus(200);
        $batchAlert = collect($overview->json('alerts'))->firstWhere('type', 'SANITARY_EXPOSURE');
        $this->assertNotNull($batchAlert);
        $this->assertSame('error', $batchAlert['severity']);
        $this->assertSame(['kind' => 'sanitary_exposure_alert', 'id' => $alert['id'], 'code' => 'ANTHONY-TORO-TEST-004'], $batchAlert['link']);

        $open = $this->apiAs('POST', '/sanitary-exposure-alerts/open-for-females', [
            'female_caravan_ids' => [$cows[0]->id, $cows[1]->id],
        ])->assertStatus(200)->json();
        $this->assertSame('ANTHONY-TORO-TEST-004', $open[(string) $cows[0]->id][0]['bull_tag']);
        $this->assertFalse($open[(string) $cows[0]->id][0]['reviewed']);
    }

    public function test_a_non_contagious_finding_raises_nothing(): void
    {
        $this->cow('EXP-V-03');
        $bull = $this->bullIn('ANTHONY-TORO-TEST-005');
        $this->startService([$bull->id]);

        $this->diagnose($bull, 'FUSOBACTERIUM_NECROPHORUM', '2026-12-01', 'IN_TREATMENT')->assertStatus(201);

        $this->assertSame([], $this->alertsOf($bull));
    }

    public function test_retiring_the_bull_closes_the_overlap_and_a_repeat_does_not_duplicate(): void
    {
        $this->cow('EXP-V-04');
        $bull = $this->bullIn('ANTHONY-TORO-TEST-006');
        $orderId = $this->startService([$bull->id]);
        $this->diagnose($bull, 'TRITRICHOMONAS_FOETUS', '2026-12-01')->assertStatus(201);

        $this->apiAs('POST', "/service-orders/{$orderId}/retire-bull", [
            'male_caravan_id' => $bull->id,
            'retirement_date' => '2026-12-02',
            'reason' => 'SANITARY_REJECTION',
            'destination_batch_id' => $this->bullPaddock->id,
        ])->assertStatus(200);

        $alerts = $this->alertsOf($bull);
        $this->assertCount(1, $alerts);
        $this->assertNull($alerts[0]['bull_active_order']);
        $detail = $this->apiAs('GET', "/sanitary-exposure-alerts/{$alerts[0]['id']}")->json();
        $this->assertSame('2026-12-02', $detail['females'][0]['overlap_until']);
    }

    public function test_females_are_reviewed_and_the_alert_is_resolved_with_a_note(): void
    {
        $cows = [$this->cow('EXP-V-05'), $this->cow('EXP-V-06')];
        $bull = $this->bullIn('ANTHONY-TORO-TEST-004');
        $this->startService([$bull->id]);
        $this->diagnose($bull, 'BRUCELLA_ABORTUS', '2026-12-01')->assertStatus(201);
        $alertId = (int) $this->alertsOf($bull)[0]['id'];

        $this->apiAs('POST', "/sanitary-exposure-alerts/{$alertId}/review-females", [
            'female_caravan_ids' => [$cows[0]->id],
            'note' => 'Tacto: preñada',
        ])->assertStatus(200)->assertJson(['reviewed' => 1]);

        $this->apiAs('POST', "/sanitary-exposure-alerts/{$alertId}/resolve", ['resolution' => ''])->assertStatus(422);
        $resolved = $this->apiAs('POST', "/sanitary-exposure-alerts/{$alertId}/resolve", [
            'resolution' => 'Vacías a descarte, resto en seguimiento.',
        ])->assertStatus(200)->json();

        $this->assertSame('RESOLVED', $resolved['status']);
        $this->assertSame(1, $resolved['reviewed_count']);
        $this->apiAs('POST', "/sanitary-exposure-alerts/{$alertId}/resolve", ['resolution' => 'otra vez'])->assertStatus(422);

        // A later recompute does not reopen the same finding.
        $this->diagnose($bull, 'BRUCELLA_ABORTUS', '2026-12-01')->assertStatus(201);
        $this->assertSame([], $this->alertsOf($bull, 'OPEN'));

        $overview = $this->apiAs('GET', "/batches/{$this->rodeo->id}/overview")->json('alerts');
        $this->assertNull(collect($overview)->firstWhere('type', 'SANITARY_EXPOSURE'));
    }

    /**
     * The tenant seeders already raise alerts for their own positive bulls: look at one bull.
     *
     * @return list<array<string, mixed>>
     */
    private function alertsOf(Caravan $bull, ?string $status = null): array
    {
        $alerts = $this->apiAs('GET', '/sanitary-exposure-alerts' . ($status !== null ? "?status={$status}" : ''))
            ->assertStatus(200)
            ->json();

        return array_values(array_filter($alerts, fn (array $a) => $a['bull']['id'] === $bull->id));
    }

    /**
     * @param int[] $bullIds
     */
    private function startService(array $bullIds): int
    {
        return (int) $this->apiAs('POST', "/batches/{$this->rodeo->id}/start-service", [
            'male_caravan_ids' => $bullIds,
            'planned_start_date' => '2026-11-01',
            'service_type' => 'single',
            'is_controlled_service' => false,
        ])->assertStatus(201)->json('id');
    }

    /**
     * @return \Illuminate\Testing\TestResponse
     */
    private function diagnose(Caravan $bull, string $pathogenCode, string $date, string $status = 'CONFIRMED_POSITIVE')
    {
        $pathogen = Pathogen::where('code', $pathogenCode)->firstOrFail();

        return $this->apiAs('POST', "/caravans/{$bull->id}/diagnoses", [
            'pathogen_id' => $pathogen->id,
            'diagnosis_date' => $date,
            'status' => $status,
        ]);
    }

    private function batch(string $name, int $activityId): Batch
    {
        return Batch::create([
            'company_id' => $this->company->id,
            'name' => $name,
            'is_active' => true,
            'activity_id' => $activityId,
        ]);
    }

    private function cow(string $tag): Caravan
    {
        return Caravan::create([
            'company_id' => $this->company->id,
            'batch_id' => $this->rodeo->id,
            'identification' => $tag,
            'sex' => 'H',
        ]);
    }

    private function bullIn(string $tag): Caravan
    {
        $bull = Caravan::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('identification', $tag)
            ->firstOrFail();
        $bull->update(['batch_id' => $this->bullPaddock->id]);

        return $bull;
    }
}
