<?php

declare(strict_types=1);

namespace Tests\Feature\WeaningOrders;

/**
 * What a weaning did differently from its order is recorded calf by calf for the audit tab, and
 * reviewed later with a note. Nothing of it blocks the weaning.
 */
class WeaningDeviationsTest extends WeaningOrderTestCase
{
    public function test_a_destination_and_a_type_declared_at_weaning_are_recorded_per_calf(): void
    {
        $calf = $this->nursingCalf('WD-A1', 'M');
        $id = $this->emitSingle([$calf])->json('id');

        $this->apiAs('POST', "/weaning-orders/{$id}/execute", [
            'weaning_type' => 'EARLY',
            'destinations' => [['key' => 's1', 'label' => '', 'stays_in_place' => true]],
            'animals' => [['caravan_id' => $calf->id, 'destination_key' => 's1']],
        ])->assertStatus(201);

        $deviations = collect($this->apiAs('GET', '/weaning-deviations?status=pending')->json())->where('identification', 'WD-A1');

        $this->assertEqualsCanonicalizing(['DESTINATION', 'WEANING_TYPE'], $deviations->pluck('kind')->all());
        $destination = $deviations->firstWhere('kind', 'DESTINATION');
        $this->assertSame($this->weaningBatch->name, $destination['planned']);
        $this->assertFalse($destination['is_reviewed']);
        $this->assertSame(\App\Core\Enums\WeaningType::EARLY->label(), $deviations->firstWhere('kind', 'WEANING_TYPE')['actual']);

        $order = $this->apiAs('GET', "/weaning-orders/{$id}")->json();
        $this->assertSame('PENDING', $order['animals'][0]['deviation_review']);
        $this->assertSame(1, $order['deviation_summary']['pending_review_head_count']);
    }

    public function test_a_category_change_an_order_did_not_ask_for_is_recorded(): void
    {
        $calf = $this->nursingCalf('WD-C1', 'M');
        $id = $this->emitSingle([$calf])->json('id');

        $this->apiAs('POST', "/weaning-orders/{$id}/execute", [
            'animals' => [['caravan_id' => $calf->id, 'category_id' => $this->categoryId('NOVILLITO')]],
        ])->assertStatus(201);

        $category = collect($this->apiAs('GET', '/weaning-deviations')->json())
            ->where('identification', 'WD-C1')
            ->firstWhere('kind', 'CATEGORY');

        $this->assertNotNull($category);
        $this->assertSame('No cambia', $category['planned']);
    }

    public function test_a_weaning_well_after_the_planned_date_is_late(): void
    {
        $onTime = $this->nursingCalf('WD-L1', 'M');
        $late = $this->nursingCalf('WD-L2', 'M');
        $onTimeId = $this->emitSingle([$onTime], ['weaning_date' => now()->subDays(3)->toDateString()])->json('id');
        $lateId = $this->emitSingle([$late], ['weaning_date' => now()->subDays(10)->toDateString()])->json('id');

        $this->apiAs('POST', "/weaning-orders/{$onTimeId}/execute")->assertStatus(201);
        $this->apiAs('POST', "/weaning-orders/{$lateId}/execute")->assertStatus(201);

        $byCalf = collect($this->apiAs('GET', '/weaning-deviations?kind=LATE')->json())->keyBy('identification');

        $this->assertArrayNotHasKey('WD-L1', $byCalf->all());
        $this->assertSame(10, $byCalf['WD-L2']['metadata']['days_late']);
    }

    public function test_calves_left_pending_when_the_order_is_closed_are_not_weaned(): void
    {
        $weaned = $this->nursingCalf('WD-N1', 'M');
        $left = $this->nursingCalf('WD-N2', 'H');
        $id = $this->emitSingle([$weaned, $left])->json('id');

        $this->apiAs('POST', "/weaning-orders/{$id}/execute", ['caravan_ids' => [$weaned->id]])->assertStatus(201);
        $this->apiAs('POST', "/weaning-orders/{$id}/close-incomplete", ['reason' => 'Vendida al pie'])->assertOk();

        $notWeaned = collect($this->apiAs('GET', '/weaning-deviations?kind=NOT_WEANED')->json())->where('weaning_order_id', $id);

        $this->assertSame(['WD-N2'], $notWeaned->pluck('identification')->values()->all());
        $this->assertStringContainsString('Vendida al pie', $notWeaned->first()['actual']);
    }

    public function test_following_the_plan_records_nothing(): void
    {
        $calf = $this->nursingCalf('WD-P1', 'M');
        $id = $this->emitSingle([$calf])->json('id');

        $this->apiAs('POST', "/weaning-orders/{$id}/execute")->assertStatus(201);

        $this->assertSame([], collect($this->apiAs('GET', '/weaning-deviations')->json())->where('weaning_order_id', $id)->values()->all());
    }

    public function test_a_review_needs_a_note_leaves_history_and_changes_nothing_else(): void
    {
        $calf = $this->nursingCalf('WD-R1', 'M');
        $id = $this->emitSingle([$calf])->json('id');
        $code = $this->apiAs('GET', "/weaning-orders/{$id}")->json('code');

        $this->apiAs('POST', "/weaning-orders/{$id}/execute", ['weaning_type' => 'ANTICIPATED'])->assertStatus(201);
        $deviationId = collect($this->apiAs('GET', "/weaning-deviations?order_code={$code}")->json())->first()['id'];
        $before = $this->apiAs('GET', "/weaning-orders/{$id}")->json();

        $this->apiAs('POST', '/weaning-deviations/review', ['ids' => [$deviationId], 'note' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors('note');

        $reviewed = $this->apiAs('POST', '/weaning-deviations/review', ['ids' => [$deviationId], 'note' => 'Se adelantó por sequía'])->assertOk();
        $this->assertTrue($reviewed->json('0.is_reviewed'));
        $this->assertSame('Se adelantó por sequía', $reviewed->json('0.review_note'));

        $after = $this->apiAs('GET', "/weaning-orders/{$id}")->json();
        $this->assertSame($before['status'], $after['status']);
        $this->assertSame('REVIEWED', $after['animals'][0]['deviation_review']);
        $entry = collect($after['history'])->firstWhere('reason', 'Se adelantó por sequía');
        $this->assertSame('DEVIATION_REVIEWED', $entry['metadata']['kind']);

        $this->assertSame([], collect($this->apiAs('GET', "/weaning-deviations?status=pending&order_code={$code}")->json())->all());
    }
}
