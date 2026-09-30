<?php

declare(strict_types=1);

namespace Tests\Feature\WeaningOrders;

use App\Models\Batch;
use App\Models\BatchWeight;
use App\Models\Caravan;

/**
 * Where the calves go (one weaning batch for all, or one per calf) and whether their category
 * changes (kept, declared on the order, or decided at the chute).
 */
class WeaningOrderDestinationsAndCategoriesTest extends WeaningOrderTestCase
{
    public function test_per_animal_takes_calves_of_two_breeding_batches_into_an_existing_and_a_new_batch(): void
    {
        $male = $this->nursingCalf('WO-PA1', 'M');
        $female = $this->nursingCalf('WO-PA2', 'H', $this->breedingB);

        $order = $this->apiAs('POST', '/weaning-orders', [
            'issue' => true,
            'destination_mode' => 'per_animal',
            'weaning_date' => now()->toDateString(),
            'destinations' => [
                ['key' => 'machos', 'label' => '', 'target_batch_id' => $this->weaningBatch->id],
                ['key' => 'hembras', 'label' => '', 'new_batch_name' => 'Destete Hembras WO', 'is_confined' => false],
            ],
            'animals' => [
                ['caravan_id' => $male->id, 'destination_key' => 'machos'],
                ['caravan_id' => $female->id, 'destination_key' => 'hembras'],
            ],
        ]);

        $order->assertStatus(201);
        $this->assertSame(['Destete Existente WO', 'Destete Hembras WO'], array_column($order->json('destinations'), 'label'));

        $result = $this->apiAs('POST', '/weaning-orders/' . $order->json('id') . '/execute', [
            'animals' => [['caravan_id' => $male->id, 'weight' => 190], ['caravan_id' => $female->id, 'weight' => 170]],
        ]);

        $result->assertStatus(201);
        $this->assertCount(2, $result->json('data.destinations'));

        $created = Batch::where('name', 'Destete Hembras WO')->firstOrFail();
        $this->assertSame($this->weaningTypeId, (int) $created->batch_type_id);
        $this->assertFalse((bool) $created->is_confined);
        $this->assertSame($this->activityId('CRIA'), (int) $created->activity_id);
        $this->assertSame($this->weaningBatch->id, (int) $male->fresh()->batch_id);
        $this->assertSame($created->id, (int) $female->fresh()->batch_id);

        // The destination remembers the batch it created, for a later round.
        $detail = $this->apiAs('GET', '/weaning-orders/' . $order->json('id'));
        $this->assertSame($created->id, collect($detail->json('destinations'))->firstWhere('label', 'Destete Hembras WO')['resolved_batch_id']);

        // Both breeding batches lose their calf, and it shows on their curve.
        foreach ([$this->breedingA, $this->breedingB] as $batch) {
            $this->assertTrue(
                BatchWeight::where('batch_id', $batch->id)->where('type', 'MOVEMENT_OUT')->exists(),
                "El rodeo {$batch->name} no registró la salida de sus crías."
            );
        }
    }

    public function test_a_calf_left_for_the_chute_cannot_be_executed_from_the_screen(): void
    {
        $decided = $this->nursingCalf('WO-CH1', 'M');
        $atChute = $this->nursingCalf('WO-CH2', 'M');

        $id = $this->apiAs('POST', '/weaning-orders', [
            'issue' => true,
            'destination_mode' => 'per_animal',
            'weaning_date' => now()->toDateString(),
            'destinations' => [['key' => 'a', 'label' => '', 'target_batch_id' => $this->weaningBatch->id]],
            'animals' => [
                ['caravan_id' => $decided->id, 'destination_key' => 'a'],
                ['caravan_id' => $atChute->id, 'destination_key' => null],
            ],
        ])->assertStatus(201)->assertJsonPath('unassigned_head_count', 1)->json('id');

        $this->apiAs('POST', "/weaning-orders/{$id}/execute")->assertStatus(422)->assertJsonPath('code', 'DESTINATION_MISSING');
        $this->assertTrue($this->isNursing($decided));
    }

    public function test_a_new_batch_without_management_system_is_asked_before_executing(): void
    {
        $calf = $this->nursingCalf('WO-MS1', 'M');

        $id = $this->emitSingle([$calf], [
            'destinations' => [['key' => 'd1', 'label' => '', 'new_batch_name' => 'Destete Sin Manejo WO']],
        ])->assertStatus(201)->json('id');

        $this->apiAs('POST', "/weaning-orders/{$id}/execute")->assertStatus(422)->assertJsonPath('code', 'NEW_BATCH_INCOMPLETE');
        $this->assertFalse(Batch::where('name', 'Destete Sin Manejo WO')->exists());
    }

    public function test_the_category_is_kept_by_default(): void
    {
        $calf = $this->nursingCalf('WO-K1', 'M');
        $id = $this->emitSingle([$calf])->assertJsonPath('category_mode', 'KEEP')->json('id');

        $this->apiAs('POST', "/weaning-orders/{$id}/execute")->assertStatus(201);

        $this->assertSame($this->categoryId('TERNERO'), (int) $calf->fresh()->category_id);
    }

    public function test_a_declared_order_changes_each_calf_to_its_own_category(): void
    {
        $male = $this->nursingCalf('WO-DC1', 'M');
        $female = $this->nursingCalf('WO-DC2', 'H');
        $keeps = $this->nursingCalf('WO-DC3', 'M');
        $reposicion = $this->subcategoryId('VAQUILLONA', 'REPOSICION');

        $order = $this->emitSingle([$male, $female, $keeps], [
            'category_mode' => 'DECLARED',
            'animals' => [
                ['caravan_id' => $male->id, 'destination_key' => 'd1', 'target_category_id' => $this->categoryId('NOVILLITO')],
                ['caravan_id' => $female->id, 'destination_key' => 'd1', 'target_category_id' => $this->categoryId('VAQUILLONA'), 'target_subcategory_id' => $reposicion],
                ['caravan_id' => $keeps->id, 'destination_key' => 'd1'],
            ],
        ]);

        $order->assertStatus(201);
        $labels = collect($order->json('animals'))->pluck('target_category_label', 'identification')->all();
        $this->assertSame('Vaquillona / Reposición', $labels['WO-DC2']);
        $this->assertNull($labels['WO-DC3']);

        $this->apiAs('POST', '/weaning-orders/' . $order->json('id') . '/execute')->assertStatus(201);

        $this->assertSame($this->categoryId('NOVILLITO'), (int) $male->fresh()->category_id);
        $this->assertSame($this->categoryId('VAQUILLONA'), (int) $female->fresh()->category_id);
        $this->assertSame($reposicion, (int) $female->fresh()->subcategory_id);
        $this->assertSame($this->categoryId('TERNERO'), (int) $keeps->fresh()->category_id);
    }

    public function test_a_declared_category_of_the_other_sex_is_refused(): void
    {
        $female = $this->nursingCalf('WO-DS1', 'H');

        $this->emitSingle([$female], [
            'category_mode' => 'DECLARED',
            'animals' => [['caravan_id' => $female->id, 'destination_key' => 'd1', 'target_category_id' => $this->categoryId('NOVILLITO')]],
        ])->assertStatus(422)->assertJsonPath('code', 'CATEGORY_TARGET_INVALID');
    }

    public function test_an_order_decided_at_the_chute_needs_a_decision_for_every_calf_when_executed_from_the_screen(): void
    {
        $male = $this->nursingCalf('WO-AC1', 'M');
        $female = $this->nursingCalf('WO-AC2', 'H');
        $id = $this->emitSingle([$male, $female], ['category_mode' => 'AT_CHUTE'])->json('id');

        // Nobody decided: refused, instead of weaning with a category nobody chose.
        $this->apiAs('POST', "/weaning-orders/{$id}/execute", [
            'animals' => [['caravan_id' => $male->id, 'category_id' => $this->categoryId('NOVILLITO')]],
        ])->assertStatus(422)->assertJsonPath('code', 'CATEGORY_DECISION_MISSING');

        // "Sin cambio" is a decision: the female is sent without a category.
        $this->apiAs('POST', "/weaning-orders/{$id}/execute", [
            'animals' => [
                ['caravan_id' => $male->id, 'category_id' => $this->categoryId('NOVILLITO')],
                ['caravan_id' => $female->id],
            ],
        ])->assertStatus(201);

        $this->assertSame($this->categoryId('NOVILLITO'), (int) $male->fresh()->category_id);
        $this->assertSame($this->categoryId('TERNERO'), (int) $female->fresh()->category_id);
    }

    public function test_a_category_chosen_on_the_screen_that_does_not_fit_the_calf_is_a_row_error(): void
    {
        $female = $this->nursingCalf('WO-SE1', 'H');
        $id = $this->emitSingle([$female], ['category_mode' => 'AT_CHUTE'])->json('id');

        $response = $this->apiAs('POST', "/weaning-orders/{$id}/execute", [
            'animals' => [['caravan_id' => $female->id, 'category_id' => $this->categoryId('NOVILLITO')]],
        ]);

        $response->assertStatus(422);
        $this->assertSame('CATEGORY_SEX_MISMATCH', $response->json('row_errors.0.errors.0.code'));
        $this->assertTrue($this->isNursing($female));
    }

    public function test_calves_in_no_batch_can_be_weaned_too(): void
    {
        $calf = $this->nursingCalf('WO-NB1', 'M');
        Caravan::whereKey($calf->id)->update(['batch_id' => null]);

        $order = $this->emitSingle([$calf]);
        $order->assertStatus(201);
        $this->assertSame([], $order->json('source_batches'));

        $this->apiAs('POST', '/weaning-orders/' . $order->json('id') . '/execute')->assertStatus(201);
        $this->assertSame($this->weaningBatch->id, (int) $calf->fresh()->batch_id);
    }
}
