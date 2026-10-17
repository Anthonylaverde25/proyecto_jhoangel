<?php

declare(strict_types=1);

namespace Tests\Feature\ServiceOrders;

use App\Models\Activity;
use App\Models\Batch;
use App\Models\Caravan;
use App\Models\CaravanGestation;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderFemale;
use App\Models\ServiceOrderMale;
use Tests\Feature\Veterinary\VeterinaryTestCase;

/**
 * A service order is closed by its pregnancy diagnosis, which can take several rounds, and never
 * by the bull withdrawal alone. ANTHONY-TORO-TEST-004..006 are seeded APT.
 */
class ServiceOrderDiagnosisClosingTest extends VeterinaryTestCase
{
    private Batch $rodeo;
    private Batch $empties;
    private Batch $bullPaddock;

    protected function setUp(): void
    {
        parent::setUp();

        $cria = Activity::updateOrCreate(['code' => 'CRIA'], ['name' => 'Cría']);

        $this->rodeo = $this->batch('Rodeo Diagnóstico Test', $cria->id);
        $this->empties = $this->batch('Vacías Diagnóstico Test', $cria->id);
        $this->bullPaddock = $this->batch('Torada Diagnóstico Test', $cria->id);
    }

    public function test_bull_withdrawal_waits_for_the_diagnosis_and_reopens_empties_scanned_with_the_bulls(): void
    {
        [$orderId, $cows] = $this->runningService(3);
        $scan = $this->day(-20);

        // Scanned empty while the bull is still in: provisional.
        $this->diagnose($orderId, $cows[0], false, $scan);
        // Scanned empty and taken out of the batch the same day: final.
        $this->diagnose($orderId, $cows[1], false, $scan, emptyBatch: $this->empties->id);

        $response = $this->apiAs('POST', "/service-orders/{$orderId}/close-service", [
            'withdrawal_date' => $this->day(-10),
            'default_destination_batch_id' => $this->bullPaddock->id,
        ])->assertStatus(200);

        $response->assertJsonPath('order.status', 'BULLS_WITHDRAWN');
        $this->assertSame([$cows[0]->id], $response->json('reopened_female_ids'));
        $this->assertSame('UNCHECKED', $this->verdictOf($orderId, $cows[0]));
        $this->assertSame('EMPTY', $this->verdictOf($orderId, $cows[1]));
        $response->assertJsonPath('order.suggested_diagnosis_date', now()->addDays(-10 + 45)->toDateString());

        // The reopened female shows her previous verdict.
        $detail = $this->apiAs('GET', "/service-orders/{$orderId}/diagnosis")->assertStatus(200);
        $reopened = collect($detail->json('females'))->firstWhere('female_caravan_id', $cows[0]->id);
        $this->assertSame('EMPTY', $reopened['previous_diagnosis']['verdict']);
        $this->assertSame($scan, $reopened['previous_diagnosis']['round_date']);
    }

    public function test_rounds_are_identified_by_their_date_and_a_recheck_keeps_the_history(): void
    {
        [$orderId, $cows] = $this->runningService(3);
        $first = $this->day(-15);
        $second = $this->day(-2);

        // Round 2 loaded first: numbers still follow the dates.
        $this->diagnose($orderId, $cows[1], true, $second);
        $this->diagnose($orderId, $cows[0], false, $first);
        $this->diagnose($orderId, $cows[2], true, $first);
        // Re-check of cow 0 in round 2.
        $this->diagnose($orderId, $cows[0], true, $second, stage: 'tail');

        $detail = $this->apiAs('GET', "/service-orders/{$orderId}/diagnosis")->assertStatus(200);
        $rounds = $detail->json('rounds');
        $this->assertCount(2, $rounds);
        $this->assertSame([1, $first, 2, 0], [$rounds[0]['number'], $rounds[0]['round_date'], $rounds[0]['diagnosed'], $rounds[0]['rechecks']]);
        $this->assertSame([2, $second, 2, 1], [$rounds[1]['number'], $rounds[1]['round_date'], $rounds[1]['diagnosed'], $rounds[1]['rechecks']]);

        $cow0 = collect($detail->json('females'))->firstWhere('female_caravan_id', $cows[0]->id);
        $this->assertSame('PREGNANT', $cow0['reproductive_status']);
        $this->assertSame('EMPTY', $cow0['previous_diagnosis']['verdict']);
        $this->assertSame(2, $cow0['diagnoses_count']);
        $this->assertSame('tail', $cow0['gestation_stage']);
        $this->assertSame(4, \App\Models\ServiceOrderFemaleDiagnosis::where('service_order_id', $orderId)->count());
    }

    public function test_pregnant_without_stage_nor_months_is_kept_as_declared(): void
    {
        [$orderId, $cows] = $this->runningService(1);

        $this->diagnose($orderId, $cows[0], true, $this->day(-1));

        $gestation = CaravanGestation::where('caravan_id', $cows[0]->id)->where('is_current', true)->firstOrFail();
        $this->assertNull($gestation->gestation_stage);
        $this->assertNull($gestation->gestation_months);
        $this->assertSame($gestation->id, ServiceOrderFemale::where('service_order_id', $orderId)->where('female_caravan_id', $cows[0]->id)->value('gestation_id'));

        $caravan = $this->apiAs('GET', "/caravans/{$cows[0]->id}")->assertStatus(200);
        $this->assertNull($caravan->json('active_gestation.gestation_stage'));
    }

    public function test_round_date_cannot_be_future_nor_before_the_service_started(): void
    {
        [$orderId, $cows] = $this->runningService(1);

        $this->diagnose($orderId, $cows[0], true, $this->day(-200), expect: 422);
        $this->diagnose($orderId, $cows[0], true, $this->day(3), expect: 422);
    }

    public function test_closing_with_pending_females_names_them_and_keeps_nothing(): void
    {
        [$orderId, $cows] = $this->withdrawnService(3);

        $response = $this->apiAs('POST', "/service-orders/{$orderId}/close-diagnosis", [
            'closing_date' => $this->day(0),
            'diagnoses' => [['caravan_id' => $cows[0]->id, 'is_pregnant' => true]],
        ])->assertStatus(422);

        $response->assertJsonPath('code', 'PENDING_DIAGNOSES');
        $this->assertEqualsCanonicalizing([$cows[1]->id, $cows[2]->id], $response->json('pending_female_ids'));
        // One transaction: the diagnosis sent with the failed closing was not kept.
        $this->assertSame('UNCHECKED', $this->verdictOf($orderId, $cows[0]));
        $this->assertSame('BULLS_WITHDRAWN', ServiceOrder::find($orderId)->status);
    }

    public function test_closing_after_withdrawal_with_screen_verdicts_fixes_the_rate(): void
    {
        [$orderId, $cows] = $this->withdrawnService(4);

        $response = $this->apiAs('POST', "/service-orders/{$orderId}/close-diagnosis", [
            'closing_date' => $this->day(0),
            'diagnoses' => [
                ['caravan_id' => $cows[0]->id, 'is_pregnant' => true, 'gestation_stage' => 'head'],
                ['caravan_id' => $cows[1]->id, 'is_pregnant' => true, 'gestation_months' => 1.5],
                ['caravan_id' => $cows[2]->id, 'is_pregnant' => false],
            ],
            'not_diagnosed' => [
                ['female_caravan_id' => $cows[3]->id, 'reason' => 'NOT_GATHERED'],
            ],
            'empties_target_batch_id' => $this->empties->id,
        ])->assertStatus(200);

        $response->assertJsonPath('order.status', 'SUCCESS');
        $response->assertJsonPath('order.diagnosis_completed_at', $this->day(0));
        // 2 pregnant over 2 + 1 empty; the female without diagnosis stays out of the rate.
        $this->assertEqualsWithDelta(66.67, (float) $response->json('order.final_pregnancy_rate'), 0.01);
        $response->assertJsonPath('order.diagnosis_summary.not_diagnosed', 1);
        $this->assertSame($this->empties->id, $cows[2]->fresh()->batch_id);
        $this->assertSame('NOT_GATHERED', ServiceOrderFemale::where('service_order_id', $orderId)->where('female_caravan_id', $cows[3]->id)->value('not_diagnosed_reason'));

        // A closed order takes no more verdicts.
        $this->diagnose($orderId, $cows[3], true, $this->day(0), expect: 422);
    }

    public function test_closing_in_service_requires_a_decision_on_the_bulls_and_the_empties_that_stay_with_them(): void
    {
        [$orderId, $cows, $bull] = $this->runningService(3, withBull: true);
        $today = $this->day(0);
        $diagnoses = [
            ['caravan_id' => $cows[0]->id, 'is_pregnant' => true],
            ['caravan_id' => $cows[1]->id, 'is_pregnant' => false],
            ['caravan_id' => $cows[2]->id, 'is_pregnant' => false],
        ];

        $this->apiAs('POST', "/service-orders/{$orderId}/close-diagnosis", [
            'closing_date' => $today,
            'diagnoses' => $diagnoses,
        ])->assertStatus(422)->assertJsonFragment(['message' => 'Indicá qué pasa con los toros que siguen en el lote.']);

        $remaining = $this->apiAs('POST', "/service-orders/{$orderId}/close-diagnosis", [
            'closing_date' => $today,
            'diagnoses' => $diagnoses,
            'bull_action' => 'STAY',
        ])->assertStatus(422);
        $this->assertStringContainsString('quedarían con los toros', (string) $remaining->json('message'));

        $closed = $this->apiAs('POST', "/service-orders/{$orderId}/close-diagnosis", [
            'closing_date' => $today,
            'diagnoses' => $diagnoses,
            'bull_action' => 'STAY',
            'remaining_females_action' => 'MOVE_OUT',
            'remaining_females_target_batch_id' => $this->empties->id,
        ])->assertStatus(200);

        $closed->assertJsonPath('order.status', 'SUCCESS');
        $this->assertSame($this->rodeo->id, $bull->fresh()->batch_id);
        $this->assertSame($this->empties->id, $cows[1]->fresh()->batch_id);
        $this->assertSame($this->empties->id, $cows[2]->fresh()->batch_id);
        $this->assertSame($this->rodeo->id, $cows[0]->fresh()->batch_id);
        $this->assertSame('COMPLETED', ServiceOrderMale::where('service_order_id', $orderId)->value('status'));
        // The empties scanned on the closing day are final.
        $this->assertEqualsWithDelta(33.33, (float) $closed->json('order.final_pregnancy_rate'), 0.01);
    }

    public function test_an_empty_scanned_before_the_closing_day_with_the_bull_still_in_must_be_rechecked(): void
    {
        [$orderId, $cows] = $this->runningService(1, withBull: true);
        $this->diagnose($orderId, $cows[0], false, $this->day(-5));

        $response = $this->apiAs('POST', "/service-orders/{$orderId}/close-diagnosis", [
            'closing_date' => $this->day(0),
            'bull_action' => 'MOVE_NOW',
            'bull_destination_batch_id' => $this->bullPaddock->id,
        ])->assertStatus(422);

        $this->assertSame([$cows[0]->id], $response->json('pending_female_ids'));
    }

    public function test_bulls_that_stay_can_go_on_with_the_empties_in_a_continuation_order(): void
    {
        [$orderId, $cows, $bull] = $this->runningService(2, withBull: true);

        $response = $this->apiAs('POST', "/service-orders/{$orderId}/close-diagnosis", [
            'closing_date' => $this->day(0),
            'diagnoses' => [
                ['caravan_id' => $cows[0]->id, 'is_pregnant' => true],
                ['caravan_id' => $cows[1]->id, 'is_pregnant' => false],
            ],
            'bull_action' => 'STAY',
            'remaining_females_action' => 'CONTINUE_SERVICE',
        ])->assertStatus(200);

        $continuationId = $response->json('continuation_order.id');
        $this->assertNotNull($continuationId);
        $continuation = ServiceOrder::find($continuationId);
        $this->assertSame('APPROVED', $continuation->status);
        $this->assertSame($orderId, $continuation->parent_service_order_id);
        $this->assertSame($this->day(0), $continuation->actual_start_date->format('Y-m-d'));
        // Only the empty cow is exposed again, to the same bull.
        $this->assertSame([$cows[1]->id], $continuation->females()->pluck('caravans.id')->all());
        $this->assertSame([$bull->id], $continuation->males()->pluck('caravans.id')->all());
    }

    public function test_bulls_can_leave_on_a_scheduled_day_through_an_issued_transfer_order(): void
    {
        [$orderId, $cows, $bull] = $this->runningService(1, withBull: true);

        $response = $this->apiAs('POST', "/service-orders/{$orderId}/close-diagnosis", [
            'closing_date' => $this->day(0),
            'diagnoses' => [['caravan_id' => $cows[0]->id, 'is_pregnant' => true]],
            'bull_action' => 'SCHEDULE',
            'bull_destination_batch_id' => $this->bullPaddock->id,
            'scheduled_movement_date' => $this->day(7),
        ])->assertStatus(200);

        $response->assertJsonPath('order.status', 'SUCCESS');
        $this->assertNotNull($response->json('transfer_order.code'));
        $this->assertDatabaseHas('transfer_orders', [
            'id' => $response->json('transfer_order.id'),
            'source_batch_id' => $this->rodeo->id,
            'status' => 'ISSUED',
        ]);
        // The bull has not moved yet.
        $this->assertSame($this->rodeo->id, $bull->fresh()->batch_id);
    }

    public function test_pregnant_found_outside_the_service_batch_after_an_empty_opens_an_incident(): void
    {
        [$orderId, $cows] = $this->withdrawnService(1, emptyFirstMovedOut: true);

        $this->diagnose($orderId, $cows[0], true, $this->day(0));

        $detail = $this->apiAs('GET', "/service-orders/{$orderId}/diagnosis")->assertStatus(200);
        $incidents = $detail->json('incidents');
        $this->assertCount(1, $incidents);
        $this->assertSame('PREGNANT_OUTSIDE_SERVICE_BATCH', $incidents[0]['type']);
        $this->assertSame('OPEN', $incidents[0]['status']);

        $this->apiAs('POST', "/service-orders/{$orderId}/incidents/{$incidents[0]['id']}/resolve", ['resolution' => ''])
            ->assertStatus(422);
        $this->apiAs('POST', "/service-orders/{$orderId}/incidents/{$incidents[0]['id']}/resolve", ['resolution' => 'Vuelve al rodeo de cría.'])
            ->assertStatus(200);
        $this->assertDatabaseHas('service_order_incidents', ['id' => $incidents[0]['id'], 'status' => 'RESOLVED']);
    }

    public function test_not_diagnosed_is_only_declared_once_the_bulls_are_gone(): void
    {
        [$orderId, $cows] = $this->runningService(1);
        $declare = fn (array $female) => $this->apiAs('POST', "/service-orders/{$orderId}/not-diagnosed", [
            'round_date' => $this->day(0),
            'females' => [['female_caravan_id' => $cows[0]->id] + $female],
        ]);

        // With the bull still in the batch there is nothing to give up on yet.
        $declare(['reason' => 'NOT_FOUND'])->assertStatus(422);

        $this->apiAs('POST', "/service-orders/{$orderId}/close-service", [
            'withdrawal_date' => $this->day(-1),
            'default_destination_batch_id' => $this->bullPaddock->id,
        ])->assertStatus(200);

        $declare(['reason' => 'OTHER'])->assertStatus(422);
        $declare(['reason' => 'OTHER', 'notes' => 'Quedó en el campo vecino.'])->assertStatus(201);
        $this->assertSame('NOT_DIAGNOSED', $this->verdictOf($orderId, $cows[0]));
    }

    public function test_status_patch_cannot_skip_the_diagnosis(): void
    {
        [$orderId] = $this->runningService(1);

        $this->apiAs('PATCH', "/service-orders/{$orderId}/status", ['status' => 'SUCCESS'])->assertStatus(422);
        $this->apiAs('PATCH', "/service-orders/{$orderId}/status", ['status' => 'BULLS_WITHDRAWN'])->assertStatus(422);
        $this->assertSame('APPROVED', ServiceOrder::find($orderId)->status);
    }

    public function test_a_pregnancy_lost_before_closing_needs_a_new_verdict(): void
    {
        [$orderId, $cows] = $this->withdrawnService(1);
        $this->diagnose($orderId, $cows[0], true, $this->day(0));
        $lossReasonId = \Illuminate\Support\Facades\DB::table('gestation_loss_reasons')->value('id');

        $this->apiAs('POST', "/caravans/{$cows[0]->id}/gestation-loss", [
            'loss_reason_id' => $lossReasonId,
            'loss_date' => $this->day(0),
        ])->assertStatus(200);

        $this->assertSame('UNCHECKED', $this->verdictOf($orderId, $cows[0]));
    }

    public function test_a_female_that_dies_without_verdict_is_resolved_as_exited(): void
    {
        [$orderId, $cows] = $this->withdrawnService(2);

        app(\App\Application\Services\ServiceExposureSynchronizer::class)
            ->onMovement($cows[0]->id, $this->rodeo->id, null, $this->day(0), 'DEATH');

        $this->assertSame('EXITED', $this->verdictOf($orderId, $cows[0]));
        $this->assertSame('UNCHECKED', $this->verdictOf($orderId, $cows[1]));
    }

    public function test_the_sheet_scan_skips_blank_cells_and_reports_unreadable_ones(): void
    {
        [$orderId, $cows] = $this->withdrawnService(3);

        $response = $this->apiAs('POST', '/caravans/import-gestation-ocr', [
            'service_order_id' => $orderId,
            'diagnosis_date' => $this->day(0),
            'rows' => [
                ['identification' => $cows[0]->identification, 'diagnostico' => 'P', 'gestation_stage' => ''],
                ['identification' => $cows[1]->identification, 'diagnostico' => ''],
                ['identification' => $cows[2]->identification, 'diagnostico' => '?'],
            ],
        ])->assertStatus(201);

        $this->assertSame(1, $response->json('data.processed'));
        $this->assertSame(1, $response->json('data.skipped'));
        $this->assertCount(1, $response->json('data.errors'));
        $this->assertSame(2, $response->json('data.order_pending'));
        $this->assertSame('PREGNANT', $this->verdictOf($orderId, $cows[0]));
        $this->assertSame('UNCHECKED', $this->verdictOf($orderId, $cows[1]));
        $this->assertSame('UNCHECKED', $this->verdictOf($orderId, $cows[2]));
    }

    // --- Fixtures ---------------------------------------------------------------------------

    /**
     * A natural service started 60 days ago on the rodeo, with one seeded APT bull.
     *
     * @return array{0: int, 1: Caravan[], 2: Caravan}
     */
    private function runningService(int $cows, bool $withBull = true): array
    {
        $females = [];
        for ($i = 1; $i <= $cows; $i++) {
            $females[] = Caravan::create([
                'company_id' => $this->company->id,
                'batch_id' => $this->rodeo->id,
                'identification' => sprintf('DX-%s-%02d', substr(uniqid(), -5), $i),
                'sex' => 'H',
                'teeth' => 4,
            ]);
        }

        $bull = Caravan::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('identification', 'ANTHONY-TORO-TEST-004')
            ->firstOrFail();
        $bull->update(['batch_id' => $this->bullPaddock->id]);

        $response = $this->apiAs('POST', "/batches/{$this->rodeo->id}/start-service", [
            'male_caravan_ids' => [$bull->id],
            'planned_start_date' => $this->day(-60),
            'service_type' => 'single',
            'is_controlled_service' => false,
        ])->assertStatus(201);

        return [(int) $response->json('id'), $females, $bull->fresh()];
    }

    /**
     * A service whose bulls were withdrawn 50 days ago (BULLS_WITHDRAWN).
     *
     * @return array{0: int, 1: Caravan[]}
     */
    private function withdrawnService(int $cows, bool $emptyFirstMovedOut = false): array
    {
        [$orderId, $females] = $this->runningService($cows);

        if ($emptyFirstMovedOut) {
            $this->diagnose($orderId, $females[0], false, $this->day(-55), emptyBatch: $this->empties->id);
        }

        $this->apiAs('POST', "/service-orders/{$orderId}/close-service", [
            'withdrawal_date' => $this->day(-50),
            'default_destination_batch_id' => $this->bullPaddock->id,
        ])->assertStatus(200);

        return [$orderId, $females];
    }

    private function diagnose(
        int $orderId,
        Caravan $cow,
        bool $pregnant,
        string $date,
        ?string $stage = null,
        ?int $emptyBatch = null,
        int $expect = 200
    ): void {
        $this->apiAs('POST', "/caravans/{$cow->id}/gestation-diagnosis", array_filter([
            'service_order_id' => $orderId,
            'is_pregnant' => $pregnant,
            'gestation_stage' => $stage,
            'diagnosis_date' => $date,
            'empty_destination_batch_id' => $emptyBatch,
        ], fn ($value) => $value !== null))->assertStatus($expect);
    }

    private function verdictOf(int $orderId, Caravan $cow): string
    {
        return (string) ServiceOrderFemale::where('service_order_id', $orderId)
            ->where('female_caravan_id', $cow->id)
            ->value('reproductive_status');
    }

    private function day(int $offset): string
    {
        return now()->addDays($offset)->toDateString();
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
}
