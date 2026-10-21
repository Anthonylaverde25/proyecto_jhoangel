<?php

declare(strict_types=1);

namespace Tests\Feature\BirthOrders;

use App\Models\Caravan;
use App\Models\CaravanLineage;
use App\Models\DeathCause;

/**
 * A mother is lactating while one of her own calves is at foot, and parting them weans the calf:
 * by a weaning (to any batch, or where it stands) or by a movement that sends them apart.
 */
class MotherNursingStateTest extends BirthOrderTestCase
{
    public function test_a_mother_lactates_while_her_calf_is_at_foot_and_the_calf_is_no_mother(): void
    {
        [$mother, $calf] = $this->calving('MN-M1', 'MN-C1', 'H');

        $this->apiAs('GET', "/caravans/{$mother->id}")->assertOk()
            ->assertJsonPath('data.physiological_state.code', 'empty_lactating')
            ->assertJsonPath('data.physiological_state.is_nursing', true)
            ->assertJsonPath('data.physiological_state.nursing_calves_count', 1);

        $this->apiAs('GET', "/caravans/{$calf->id}")->assertOk()
            ->assertJsonPath('data.physiological_state.code', 'unknown')
            ->assertJsonPath('data.lineage.is_nursing', true);
    }

    public function test_weaning_where_the_calf_stands_dries_the_mother(): void
    {
        [$mother, $calf] = $this->calving('MN-M2', 'MN-C2');

        $this->apiAs('POST', '/weaning-orders/register', [
            'destination_mode' => 'single',
            'weaning_date' => now()->toDateString(),
            'weaning_type' => 'EARLY',
            'destinations' => [['key' => 'stay', 'label' => '', 'stays_in_place' => true]],
            'animals' => [['caravan_id' => $calf->id, 'destination_key' => 'stay']],
        ])->assertStatus(201);

        $this->assertSame($this->breedingA->id, (int) $calf->fresh()->batch_id);
        $lineage = CaravanLineage::where('caravan_id', $calf->id)->firstOrFail();
        $this->assertFalse((bool) $lineage->is_nursing);
        $this->assertSame('WEANED', $lineage->nursing_end_reason);
        $this->assertSame('EARLY', $lineage->weaning_type);
        $this->assertSame(now()->toDateString(), $lineage->nursing_ended_at->format('Y-m-d'));

        $this->apiAs('GET', "/caravans/{$mother->id}")->assertJsonPath('data.physiological_state.code', 'empty_dry');
    }

    public function test_a_weaning_may_go_to_any_own_batch_not_only_a_weaning_batch(): void
    {
        [, $calf] = $this->calving('MN-M3', 'MN-C3');

        $this->apiAs('POST', '/weaning-orders/register', [
            'destination_mode' => 'single',
            'weaning_date' => now()->toDateString(),
            'weaning_type' => 'TRADITIONAL',
            'destinations' => [['key' => 'b', 'label' => '', 'target_batch_id' => $this->breedingB->id]],
            'animals' => [['caravan_id' => $calf->id, 'destination_key' => 'b']],
        ])->assertStatus(201);

        $this->assertSame($this->breedingB->id, (int) $calf->fresh()->batch_id);
        $this->assertSame('TRADITIONAL', CaravanLineage::where('caravan_id', $calf->id)->value('weaning_type'));
    }

    public function test_a_dead_calf_dries_its_mother(): void
    {
        [$mother, $calf] = $this->calving('MN-M4', 'MN-C4');
        $cause = DeathCause::where('company_id', $this->company->id)->where('code', 'UNKNOWN')->firstOrFail();

        $this->apiAs('POST', '/caravans/deaths', [
            'caravan_ids' => [$calf->id],
            'death_date' => now()->toDateString(),
            'death_cause_id' => $cause->id,
        ])->assertStatus(201);

        $this->assertSame('DIED', CaravanLineage::where('caravan_id', $calf->id)->value('nursing_end_reason'));
        $this->apiAs('GET', "/caravans/{$mother->id}")->assertJsonPath('data.physiological_state.code', 'empty_dry');
    }

    public function test_a_move_that_parts_them_asks_for_the_weaning_and_then_weans(): void
    {
        [$mother, $calf] = $this->calving('MN-M5', 'MN-C5');

        // The calf alone: nothing moves until the kind of weaning is chosen.
        $this->apiAs('POST', '/caravans/bulk-transfer', ['caravan_ids' => [$calf->id], 'target_batch_id' => $this->breedingB->id])
            ->assertStatus(422)
            ->assertJsonPath('code', 'MOTHER_CALF_SEPARATION')
            ->assertJsonStructure(['errors' => ['weaning_type']]);
        $this->assertSame($this->breedingA->id, (int) $calf->fresh()->batch_id);

        $this->apiAs('POST', '/caravans/bulk-transfer', [
            'caravan_ids' => [$calf->id],
            'target_batch_id' => $this->breedingB->id,
            'weaning_type' => 'ANTICIPATED',
        ])->assertOk();

        $this->assertSame($this->breedingB->id, (int) $calf->fresh()->batch_id);
        $this->assertSame('ANTICIPATED', CaravanLineage::where('caravan_id', $calf->id)->value('weaning_type'));
        $this->apiAs('GET', "/caravans/{$mother->id}")->assertJsonPath('data.physiological_state.code', 'empty_dry');
    }

    public function test_moving_the_mother_alone_weans_the_calf_where_it_stands_and_moving_both_weans_nobody(): void
    {
        [$mother, $calf] = $this->calving('MN-M6', 'MN-C6');
        [$other, $otherCalf] = $this->calving('MN-M7', 'MN-C7');

        // Both together: no one is parted.
        $this->apiAs('POST', '/caravans/bulk-transfer', ['caravan_ids' => [$other->id, $otherCalf->id], 'target_batch_id' => $this->breedingB->id])
            ->assertOk();
        $this->assertTrue((bool) CaravanLineage::where('caravan_id', $otherCalf->id)->value('is_nursing'));

        // The mother alone, with the kind chosen: her calf stays and is weaned.
        $this->apiAs('POST', '/caravans/bulk-transfer', [
            'caravan_ids' => [$mother->id],
            'target_batch_id' => $this->breedingB->id,
            'weaning_type' => 'TRADITIONAL',
        ])->assertOk();

        $this->assertSame($this->breedingA->id, (int) $calf->fresh()->batch_id);
        $this->assertFalse((bool) CaravanLineage::where('caravan_id', $calf->id)->value('is_nursing'));
    }

    public function test_a_registered_transfer_that_parts_them_needs_the_kind_of_weaning(): void
    {
        [, $calf] = $this->calving('MN-M8', 'MN-C8');
        $payload = [
            'source_batch_id' => $this->breedingA->id,
            'destination_activity_id' => $this->activityId('CRIA'),
            'destination_mode' => 'single',
            'movement_date' => now()->toDateString(),
            'destinations' => [['key' => 'dest-single', 'label' => '', 'target_batch_id' => $this->breedingB->id]],
            'animals' => [['caravan_id' => $calf->id, 'destination_key' => 'dest-single']],
        ];

        $this->apiAs('POST', '/transfer-orders/register', $payload)->assertStatus(422)->assertJsonPath('code', 'MOTHER_CALF_SEPARATION');
        $this->apiAs('POST', '/transfer-orders/register', [...$payload, 'weaning_type' => 'EARLY'])->assertStatus(201);

        $this->assertSame('EARLY', CaravanLineage::where('caravan_id', $calf->id)->value('weaning_type'));
    }

    /**
     * @return array{0: Caravan, 1: Caravan} the mother and her tagged calf at foot
     */
    private function calving(string $motherTag, string $calfTag, string $sex = 'M'): array
    {
        $mother = $this->pregnantFemale($motherTag);

        $response = $this->apiAs('POST', '/birth-orders/register', [
            'animals' => [['caravan_id' => $mother->id, 'outcome' => 'LIVE', 'calf_identification' => $calfTag, 'calf_sex' => $sex, 'birth_date' => now()->toDateString()]],
        ])->assertStatus(201);

        return [$mother, Caravan::findOrFail(collect($response->json('order.animals'))->firstWhere('caravan_id', $mother->id)['calf_caravan_id'])];
    }
}
