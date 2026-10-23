<?php

declare(strict_types=1);

namespace Tests\Feature\Insemination;

use App\Models\Caravan;
use App\Models\ServiceOrderFemale;

/** "Iniciar servicio" with AI (semen S-B, IA1-IA4, DB2). */
class StartInseminationServiceTest extends InseminationTestCase
{
    public function test_starts_without_bulls_with_the_picked_females_and_their_planned_semen(): void
    {
        [$a, $b, $c] = $this->cows(3);

        $order = $this->startAi([$a, $b], [$a->id => $this->semenItem])->assertStatus(201)->json();

        $this->assertSame('AI', $order['service_method']);
        $this->assertSame('APPROVED', $order['status']);
        $this->assertSame([], $order['all_male_caravan_ids']);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $order['female_caravan_ids']);
        $this->assertSame(['females' => 2, 'inseminated' => 0, 'not_inseminated' => 2, 'last_insemination_on' => null, 'repaso_bulls_active' => 0, 'had_repaso_bulls' => false], $order['insemination_summary']);
        $this->assertSame($this->semenItem, ServiceOrderFemale::where('service_order_id', $order['id'])->where('female_caravan_id', $a->id)->value('planned_semen_item_id'));
        $this->assertNull(ServiceOrderFemale::where('service_order_id', $order['id'])->where('female_caravan_id', $b->id)->value('planned_semen_item_id'));
        $this->assertSame(0, \App\Models\CaravanMovement::whereIn('caravan_id', [$a->id, $b->id, $c->id])->count());
    }

    public function test_excluded_females_and_wrong_semen_are_refused(): void
    {
        [$a, $excluded] = $this->cows(2);
        $excluded->update(['is_breeding_eligible' => false, 'breeding_exclusion_reason' => 'CUT_DENTITION']);

        $this->startAi([$a, $excluded])->assertStatus(422);
        [$notSemen] = $this->stockedItem("Ivermectina IA");
        $this->startAi([$a], [$a->id => $notSemen])->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'no es un semen'));

        // Without picking anyone, every eligible female enters; the excluded one stays out.
        $order = $this->apiAs('POST', "/batches/{$this->rodeo->id}/start-service", [
            'service_method' => 'AI', 'planned_start_date' => $this->start,
        ])->assertStatus(201);
        $this->assertSame([$a->id], $order->json('female_caravan_ids'));
    }

    public function test_one_active_order_per_batch_and_newcomers_are_not_added(): void
    {
        [$a] = $this->cows(1);
        $order = $this->startAi([$a])->assertStatus(201)->json();
        $this->startAi([$a])->assertStatus(422);

        $newcomer = $this->cows(1, $this->bullPaddock)[0];
        $this->apiAs('POST', '/caravans/bulk-transfer', [
            'caravan_ids' => [$newcomer->id], 'target_batch_id' => $this->rodeo->id, 'movement_date' => now()->toDateString(),
        ])->assertStatus(200);

        $this->assertSame([$a->id], $this->order($order['id'])['female_caravan_ids']);
    }

    public function test_natural_service_still_needs_bulls(): void
    {
        $this->cows(1);
        $this->apiAs('POST', "/batches/{$this->rodeo->id}/start-service", ['planned_start_date' => $this->start])->assertStatus(422);
    }
}
