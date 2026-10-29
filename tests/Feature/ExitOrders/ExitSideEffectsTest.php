<?php

declare(strict_types=1);

namespace Tests\Feature\ExitOrders;

use App\Models\CaravanGestation;
use App\Models\CaravanLineage;

/**
 * What leaving does to the animal (01 §5): a pregnant female's gestation is closed as sold, and a
 * cow with a calf at foot says what happens to it (P1).
 */
class ExitSideEffectsTest extends ExitOrderTestCase
{
    public function test_a_pregnant_female_leaves_with_her_gestation_closed_as_sold(): void
    {
        $female = $this->pregnantFemale('EX-S1');

        $this->registerExit([$this->line($female)])
            ->assertStatus(201)->assertJsonPath('animals.0.was_pregnant', true);

        $gestation = CaravanGestation::where('caravan_id', $female->id)->latest('id')->firstOrFail();
        $this->assertFalse((bool) $gestation->is_current);
        $this->assertNull($gestation->success);
        $this->assertSame('Vendida preñada', $gestation->notes);
    }

    public function test_the_screen_learns_which_cows_carry_a_calf_at_foot(): void
    {
        [$cow, $calf] = $this->cowWithCalf('EX-S4');
        $steer = $this->steer('EX-S5');

        $this->getJson("/api/exit-orders/calves-at-foot?caravan_ids={$cow->id},{$steer->id}")
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.mother_id', $cow->id)
            ->assertJsonPath('0.calf_id', $calf->id);
    }

    public function test_a_cow_with_a_calf_at_foot_says_what_happens_to_it(): void
    {
        [$cow, $calf] = $this->cowWithCalf('EX-S2');

        $this->registerExit([$this->line($cow)])
            ->assertStatus(422)->assertJsonValidationErrors('lines.0.caravan_id');

        $this->registerExit([$this->line($cow, ['calf_decision' => 'WITH_MOTHER'])])
            ->assertStatus(201)->assertJsonPath('exited_count', 2);

        $this->assertSame('INTERNAL_EXIT', $this->exitBatchOf($calf));
        $lineage = CaravanLineage::where('caravan_id', $calf->id)->firstOrFail();
        $this->assertFalse((bool) $lineage->is_nursing);
        $this->assertSame('EXITED', $lineage->nursing_end_reason);
    }

    public function test_a_calf_that_stays_is_weaned_where_it_stands(): void
    {
        [$cow, $calf] = $this->cowWithCalf('EX-S3');

        $this->registerExit([$this->line($cow, ['calf_decision' => 'STAYS'])])
            ->assertStatus(201)->assertJsonPath('exited_count', 1);

        $this->assertSame($this->breedingA->id, (int) $calf->fresh()->batch_id);
        $lineage = CaravanLineage::where('caravan_id', $calf->id)->firstOrFail();
        $this->assertFalse((bool) $lineage->is_nursing);
        $this->assertSame('WEANED', $lineage->nursing_end_reason);
    }
}
