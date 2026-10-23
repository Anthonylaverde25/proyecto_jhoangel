<?php

declare(strict_types=1);

namespace Tests\Feature\Insemination;

use Illuminate\Support\Facades\DB;

/**
 * End of an AI service, repaso bulls, tacto and closing (semen S-B, IA10-IA13, DB1, DB5, DB11).
 * ANTHONY-TORO-TEST-004..006 are seeded APT.
 */
class InseminationServiceLifecycleTest extends InseminationTestCase
{
    public function test_close_from_service_without_repaso_leaves_the_never_inseminated_out(): void
    {
        [$a, $b, $c] = $this->cows(3);
        $orderId = (int) $this->startAi([$a, $b, $c])->assertStatus(201)->json('id');
        $this->inseminate($orderId, [$this->row($a, $this->lotA), $this->row($b, $this->lotA)])->assertStatus(201);

        // Early scan after the insemination: an empty is final (IA11); C was never inseminated (IA12).
        $this->tacto($orderId, $a, true)->assertSuccessful();
        $this->tacto($orderId, $b, false)->assertSuccessful();
        $this->tacto($orderId, $c, false)->assertStatus(422);

        $order = $this->order($orderId);
        $this->assertSame(2, $order['diagnosis_summary']['total']);
        $this->assertSame(1, $order['diagnosis_summary']['not_inseminated']);
        $this->assertSame(0, $order['diagnosis_summary']['pending']);
        $this->assertSame(0, $order['diagnosis_summary']['provisional_empty']);

        // "Cerrar orden" straight from service (DB5), with the optional note.
        $this->apiAs('POST', "/service-orders/{$orderId}/close-diagnosis", [
            'closing_date' => now()->toDateString(),
            'not_inseminated_reasons' => [['female_caravan_id' => $c->id, 'reason' => 'NOT_PRESENTED'], ['female_caravan_id' => $a->id, 'reason' => 'OTHER']],
        ])->assertOk()
            ->assertJsonPath('order.status', 'SUCCESS')
            ->assertJsonPath('order.final_pregnancy_rate', 50);

        $reasons = DB::table('service_order_females')->where('service_order_id', $orderId)->pluck('not_inseminated_reason', 'female_caravan_id');
        $this->assertSame('NOT_PRESENTED', $reasons[$c->id]);
        $this->assertNull($reasons[$a->id], 'only the never inseminated get a reason');
    }

    public function test_reinseminating_an_empty_makes_her_pending_again(): void
    {
        [$a] = $this->cows(1);
        $orderId = (int) $this->startAi([$a])->assertStatus(201)->json('id');
        $this->inseminate($orderId, [$this->row($a, $this->lotA, date: now()->subDays(15)->toDateString())])->assertStatus(201);
        $this->tacto($orderId, $a, false, now()->subDays(5)->toDateString())->assertSuccessful();
        $this->assertSame(0, $this->order($orderId)['diagnosis_summary']['pending']);

        $this->inseminate($orderId, [$this->row($a, $this->lotA, date: now()->subDays(2)->toDateString())])->assertStatus(201);
        $summary = $this->order($orderId)['diagnosis_summary'];
        $this->assertSame(1, $summary['pending']);
        $this->assertSame(1, $summary['provisional_empty']);
    }

    public function test_end_service_from_the_menu_frees_the_batch(): void
    {
        [$a, $b] = $this->cows(2);
        $orderId = (int) $this->startAi([$a, $b])->assertStatus(201)->json('id');
        $this->inseminate($orderId, [$this->row($a, $this->lotA, date: now()->subDays(10)->toDateString())])->assertStatus(201);

        $this->apiAs('POST', "/service-orders/{$orderId}/end-service", ['end_date' => now()->subDays(11)->toDateString()])->assertStatus(422);
        $ended = $this->apiAs('POST', "/service-orders/{$orderId}/end-service", [
            'end_date' => now()->subDays(5)->toDateString(),
            'not_inseminated_reasons' => [['female_caravan_id' => $b->id, 'reason' => 'DEVICE_LOST']],
        ])->assertOk();
        $ended->assertJsonPath('status', 'SERVICE_ENDED');
        $this->assertSame(now()->subDays(10)->addDays(30)->toDateString(), $ended->json('suggested_diagnosis_date'));
        $this->assertSame('DEVICE_LOST', DB::table('service_order_females')->where('service_order_id', $orderId)->where('female_caravan_id', $b->id)->value('not_inseminated_reason'));
        $this->assertSame(0, DB::table('service_order_females')->where('service_order_id', $orderId)->whereNull('exposed_until')->count());

        // A late load within the service still fits; after its end does not.
        $this->inseminate($orderId, [$this->row($b, $this->lotA, date: now()->subDays(7)->toDateString())])->assertStatus(201);
        $this->inseminate($orderId, [$this->row($b, $this->lotA, date: now()->subDays(1)->toDateString())])->assertStatus(422);

        // The batch takes another service while the tacto goes on.
        $this->startAi($this->cows(1))->assertStatus(201);
        $this->tacto($orderId, $a, true)->assertSuccessful();
    }

    public function test_repaso_bulls_join_and_retirar_torada_ends_the_service(): void
    {
        [$a, $b] = $this->cows(2);
        $bull = $this->bullIn('ANTHONY-TORO-TEST-004', $this->bullPaddock);
        $orderId = (int) $this->startAi([$a, $b])->assertStatus(201)->json('id');
        $this->inseminate($orderId, [$this->row($a, $this->lotA, date: now()->subDays(15)->toDateString())])->assertStatus(201);

        // Without bulls there is nothing to withdraw.
        $this->apiAs('POST', "/service-orders/{$orderId}/close-service", ['withdrawal_date' => now()->toDateString()])->assertStatus(422);

        $this->apiAs('POST', "/service-orders/{$orderId}/repaso-bulls", ['male_caravan_ids' => [$bull->id], 'joined_at' => now()->subDays(10)->toDateString()])
            ->assertOk()
            ->assertJsonPath('insemination_summary.repaso_bulls_active', 1);
        $this->assertSame($this->rodeo->id, $bull->fresh()->batch_id);

        // With repaso every female shared the paddock: B is scanned too, and an empty is provisional.
        $this->tacto($orderId, $b, false, now()->subDays(2)->toDateString())->assertSuccessful();
        $this->assertSame(1, $this->order($orderId)['diagnosis_summary']['provisional_empty']);
        $this->apiAs('POST', "/service-orders/{$orderId}/end-service", ['end_date' => now()->toDateString()])->assertStatus(422);

        $this->apiAs('POST', "/service-orders/{$orderId}/close-service", [
            'withdrawal_date' => now()->subDay()->toDateString(),
            'default_destination_batch_id' => $this->bullPaddock->id,
        ])->assertOk();
        $order = $this->order($orderId);
        $this->assertSame('SERVICE_ENDED', $order['status']);
        $this->assertSame($this->bullPaddock->id, $bull->fresh()->batch_id);
        // The empty scanned with the bull in the batch went back to pending.
        $this->assertSame(2, $order['diagnosis_summary']['pending']);
    }
}
