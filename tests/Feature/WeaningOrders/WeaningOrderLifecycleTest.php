<?php

declare(strict_types=1);

namespace Tests\Feature\WeaningOrders;

use App\Models\Batch;
use App\Models\BatchType;
use App\Models\CaravanLineage;
use App\Models\CaravanMovement;
use App\Models\CaravanWeight;
use App\Models\WeaningOrderAnimal;

/**
 * A weaning order from draft to its end: issued, executed from the screen, partial, closed
 * incomplete or cancelled — and what it commits while it is open.
 */
class WeaningOrderLifecycleTest extends WeaningOrderTestCase
{
    public function test_a_draft_gets_its_code_and_declares_the_weaning_activity(): void
    {
        $a = $this->nursingCalf('WO-L1', 'M');
        $b = $this->nursingCalf('WO-L2', 'H', $this->breedingB);

        $response = $this->emitSingle([$a, $b], ['issue' => false]);

        $response->assertStatus(201);
        $this->assertSame('DRAFT', $response->json('status'));
        $this->assertSame('PLANNED', $response->json('kind'));
        $this->assertMatchesRegularExpression('/^DS-\d{8}-\d{4}$/', (string) $response->json('code'));
        $this->assertSame($this->activityId('CRIA'), $response->json('destination_activity.id'));
        $this->assertSame('TRADITIONAL', $response->json('weaning_type'));
        $this->assertSame('Tradicional', $response->json('weaning_type_label'));

        // One order, two breeding batches: the source lives on each line.
        $sources = collect($response->json('source_batches'))->pluck('head_count', 'name')->all();
        $this->assertSame(['Rodeo Cría A WO' => 1, 'Rodeo Cría B WO' => 1], $sources);

        $mothers = collect($response->json('animals'))->pluck('mother_identification', 'identification')->all();
        $this->assertSame('WO-L1-M', $mothers['WO-L1']);

        // A draft commits nothing and weans nothing.
        $this->assertTrue($this->isNursing($a));
    }

    public function test_issuing_a_draft_and_then_executing_it_from_the_screen(): void
    {
        $a = $this->nursingCalf('WO-E1', 'M');
        $b = $this->nursingCalf('WO-E2', 'H');
        $id = $this->emitSingle([$a, $b], ['issue' => false])->json('id');

        $this->apiAs('POST', "/weaning-orders/{$id}/issue")->assertStatus(200)->assertJsonPath('status', 'ISSUED');

        $response = $this->apiAs('POST', "/weaning-orders/{$id}/execute", [
            'animals' => [['caravan_id' => $a->id, 'weight' => 182.5]],
        ]);

        $response->assertStatus(201);
        $this->assertSame('EXECUTED', $response->json('data.weaning_order.status'));
        $this->assertSame(2, $response->json('data.weaning_order.weaned_now'));
        $this->assertSame(1, $response->json('data.weighed_count'));

        foreach ([$a, $b] as $calf) {
            $this->assertFalse($this->isNursing($calf));
            $this->assertSame($this->weaningBatch->id, (int) $calf->fresh()->batch_id);
        }

        // Each line points at the movement that fulfilled it.
        $line = WeaningOrderAnimal::where('caravan_id', $a->id)->firstOrFail();
        $movement = CaravanMovement::findOrFail($line->caravan_movement_id);
        $this->assertSame('WEANING', $movement->type);
        $this->assertSame($this->breedingA->id, (int) $movement->from_batch_id);
        $this->assertStringContainsString('Orden ', (string) $movement->observations);
        $this->assertEquals(182.5, (float) CaravanWeight::where('caravan_id', $a->id)->where('current', true)->value('weight'));

        $history = $this->apiAs('GET', "/weaning-orders/{$id}")->json('history');
        $this->assertSame('SCREEN', end($history)['metadata']['origin']);
    }

    public function test_the_weaning_date_is_declared_and_never_future_nor_before_the_order(): void
    {
        $calf = $this->nursingCalf('WO-D1', 'M');
        $id = $this->emitSingle([$calf])->json('id');

        $this->apiAs('POST', "/weaning-orders/{$id}/execute", ['weaning_date' => now()->addDay()->toDateString()])
            ->assertStatus(422)
            ->assertJsonValidationErrors('weaning_date');

        $before = $this->apiAs('POST', "/weaning-orders/{$id}/execute", ['weaning_date' => now()->subDays(3)->toDateString()]);
        $before->assertStatus(422);
        $this->assertSame('WEANING_BEFORE_ORDER', $before->json('header_errors.0.code'));
        $this->assertTrue($this->isNursing($calf));
    }

    public function test_a_calf_is_committed_to_one_open_order_at_a_time(): void
    {
        $calf = $this->nursingCalf('WO-C1', 'M');
        $first = $this->emitSingle([$calf])->json('code');

        $second = $this->emitSingle([$calf]);
        $second->assertStatus(422)->assertJsonPath('code', 'ANIMAL_IN_OPEN_ORDER');
        $this->assertStringContainsString($first, (string) $second->json('message'));

        // A draft commits nothing: it can be saved, not issued.
        $draft = $this->emitSingle([$calf], ['issue' => false]);
        $draft->assertStatus(201);
        $this->apiAs('POST', '/weaning-orders/' . $draft->json('id') . '/issue')
            ->assertStatus(422)
            ->assertJsonPath('code', 'ANIMAL_IN_OPEN_ORDER');
    }

    public function test_a_calf_held_by_a_weaning_order_cannot_be_taken_by_a_transfer_order_and_the_other_way_round(): void
    {
        $recria = Batch::create([
            'company_id' => $this->company->id,
            'name' => 'Recría WO',
            'activity_id' => $this->activityId('RECRIA'),
            'batch_type_id' => (int) BatchType::withoutGlobalScopes()->where('code', 'OPERATIONAL')->value('id'),
            'is_confined' => false,
            'is_active' => true,
        ]);
        $transfer = fn ($calf) => $this->apiAs('POST', '/transfer-orders', [
            'issue' => true,
            'source_batch_id' => $this->breedingA->id,
            'destination_activity_id' => $this->activityId('RECRIA'),
            'destination_mode' => 'single',
            'movement_date' => now()->toDateString(),
            'destinations' => [['key' => 'r', 'label' => '', 'target_batch_id' => $recria->id]],
            'animals' => [['caravan_id' => $calf->id, 'destination_key' => 'r']],
        ]);

        $weaned = $this->nursingCalf('WO-X1', 'M');
        $code = $this->emitSingle([$weaned])->json('code');
        $refused = $transfer($weaned);
        $refused->assertStatus(422)->assertJsonPath('code', 'ANIMAL_IN_OPEN_ORDER');
        $this->assertStringContainsString($code, (string) $refused->json('message'));

        // The other way round: a calf an issued transfer order holds cannot be weaned by order.
        $moved = $this->nursingCalf('WO-X2', 'H');
        $transfer($moved)->assertStatus(201);

        $this->emitSingle([$moved])->assertStatus(422)->assertJsonPath('code', 'ANIMAL_IN_OPEN_ORDER');
    }

    public function test_only_calves_at_foot_can_be_ordered(): void
    {
        $weaned = $this->nursingCalf('WO-N1', 'M');
        CaravanLineage::where('caravan_id', $weaned->id)->update(['is_nursing' => false]);

        $this->emitSingle([$weaned])->assertStatus(422)->assertJsonPath('code', 'ALREADY_WEANED');
    }

    public function test_a_destination_has_to_be_a_weaning_batch(): void
    {
        $calf = $this->nursingCalf('WO-B1', 'M');

        $this->emitSingle([$calf], [
            'destinations' => [['key' => 'd1', 'label' => '', 'target_batch_id' => $this->breedingA->id]],
        ])->assertStatus(422)->assertJsonPath('code', 'NOT_A_WEANING_BATCH');

        $this->emitSingle([$calf], [
            'destinations' => [['key' => 'd1', 'label' => '', 'new_batch_name' => 'Rodeo Cría A WO']],
        ])->assertStatus(422)->assertJsonPath('code', 'BATCH_NAME_IN_USE');
    }

    public function test_closing_incomplete_skips_the_pending_calves_and_frees_them(): void
    {
        $a = $this->nursingCalf('WO-P1', 'M');
        $b = $this->nursingCalf('WO-P2', 'H');
        $id = $this->emitSingle([$a, $b])->json('id');

        // The sheet brings only one of the two calves.
        $this->scanSingle($id, [[$a, 170.0]])->assertStatus(201)->assertJsonPath('data.weaning_order.status', 'PARTIAL');

        $this->apiAs('POST', "/weaning-orders/{$id}/close-incomplete", ['reason' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $closed = $this->apiAs('POST', "/weaning-orders/{$id}/close-incomplete", ['reason' => 'Se quedó con la madre por bajo peso']);
        $closed->assertStatus(200);
        $this->assertSame('CLOSED_INCOMPLETE', $closed->json('status'));
        $this->assertSame(1, $closed->json('weaned_head_count'));
        $this->assertSame(1, $closed->json('skipped_head_count'));

        // No longer committed: another order can take it.
        $this->emitSingle([$b])->assertStatus(201);
    }

    public function test_cancelling_an_issued_order_asks_for_a_reason_and_a_draft_does_not(): void
    {
        $calf = $this->nursingCalf('WO-A1', 'M');
        $issued = $this->emitSingle([$calf])->json('id');

        $this->apiAs('POST', "/weaning-orders/{$issued}/cancel", [])->assertStatus(422)->assertJsonPath('code', 'REASON_REQUIRED');
        $this->apiAs('POST', "/weaning-orders/{$issued}/cancel", ['reason' => 'Se posterga'])->assertJsonPath('status', 'CANCELLED');

        $draft = $this->emitSingle([$calf], ['issue' => false])->json('id');
        $this->apiAs('POST', "/weaning-orders/{$draft}/cancel", [])->assertJsonPath('status', 'CANCELLED');
    }

    public function test_a_draft_is_not_printed_and_an_issued_order_is_stamped_once(): void
    {
        $calf = $this->nursingCalf('WO-PR1', 'M');
        $draft = $this->emitSingle([$calf], ['issue' => false])->json('id');

        $this->apiAs('POST', "/weaning-orders/{$draft}/printed")->assertStatus(422)->assertJsonPath('code', 'DRAFT_NOT_PRINTABLE');

        $this->apiAs('POST', "/weaning-orders/{$draft}/issue");
        $first = $this->apiAs('POST', "/weaning-orders/{$draft}/printed")->json('printed_at');
        $this->assertNotNull($first);
        $this->assertSame($first, $this->apiAs('POST', "/weaning-orders/{$draft}/printed")->json('printed_at'));
    }

    public function test_the_code_read_off_paper_is_cleaned_before_looking_it_up(): void
    {
        $calf = $this->nursingCalf('WO-O1', 'M');
        $code = (string) $this->emitSingle([$calf])->json('code');
        $misread = strtolower(str_replace('0', 'O', $code));

        $this->apiAs('GET', '/weaning-orders/by-code/' . urlencode($misread))->assertStatus(200)->assertJsonPath('code', $code);
        $this->apiAs('GET', '/weaning-orders/by-code/DS-19990101-0001')->assertStatus(404);

        // A reading that lost leading zeros of the sequence ("-001" for "-0001") still finds it.
        $shortSequence = preg_replace('/-0(\d{3})$/', '-$1', $code);
        $this->assertNotSame($code, $shortSequence);
        $this->apiAs('GET', '/weaning-orders/by-code/' . urlencode($shortSequence))->assertStatus(200)->assertJsonPath('code', $code);
    }

    public function test_the_list_filters_by_status_and_kind(): void
    {
        $draft = $this->emitSingle([$this->nursingCalf('WO-F1', 'M')], ['issue' => false])->json('code');
        $issued = $this->emitSingle([$this->nursingCalf('WO-F2', 'M')])->json('code');

        // The tenant seed issues orders of its own: only these two are looked at.
        $mine = fn (string $query) => array_values(array_intersect(
            array_column($this->apiAs('GET', "/weaning-orders?{$query}")->json(), 'code'),
            [$issued, $draft]
        ));

        $this->assertSame([$draft], $mine('status=draft'));
        $this->assertSame([$issued, $draft], $mine('kind=PLANNED'));
        $this->assertSame([], $mine('kind=REGISTERED'));
    }

    public function test_the_births_list_names_the_open_order_that_holds_a_calf(): void
    {
        $held = $this->nursingCalf('WO-BH1', 'M');
        $free = $this->nursingCalf('WO-BH2', 'H');
        $code = $this->emitSingle([$held])->json('code');

        $byCalf = collect($this->apiAs('GET', '/caravans/births-history')->json())->keyBy('calf_identification');

        $this->assertSame($code, $byCalf['WO-BH1']['open_order_code']);
        $this->assertNull($byCalf['WO-BH2']['open_order_code']);
    }
}
