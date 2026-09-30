<?php

declare(strict_types=1);

namespace Tests\Feature\WeaningOrders;

use App\Models\Batch;
use App\Models\WeaningOrder;

/**
 * The DEST-01 sheet against a weaning order: the paper that carries the order's code fulfils it,
 * all at once or in rounds, and a sheet printed blank gets its order when it is confirmed.
 */
class WeaningOrderSheetTest extends WeaningOrderTestCase
{
    public function test_an_order_fulfilled_in_two_rounds_reuses_the_batch_the_first_one_created(): void
    {
        $a = $this->nursingCalf('WO-S1', 'M');
        $b = $this->nursingCalf('WO-S2', 'H');
        $order = $this->emitSingle([$a, $b], [
            'destinations' => [['key' => 'd1', 'label' => '', 'new_batch_name' => 'Destete Dos Tandas WO', 'is_confined' => false]],
        ]);
        $id = $order->json('id');
        $sheetDestination = ['destinations' => [[
            'key' => 'Destete Dos Tandas WO',
            'target_batch_id' => null,
            'new_batch' => ['name' => 'Destete Dos Tandas WO', 'is_confined' => false],
        ]]];

        $first = $this->scanSingle($id, [[$a, 175.0]], $sheetDestination);
        $first->assertStatus(201);
        $this->assertSame('PARTIAL', $first->json('data.weaning_order.status'));
        $this->assertSame(['WO-S2'], $first->json('data.weaning_order.pending_identifications'));
        $this->assertContains('WEANING_ORDER_PARTIAL', array_column($first->json('data.warnings'), 'code'));

        // The second round names the same new batch: it goes to the one already created.
        $second = $this->scanSingle($id, [[$b, 168.0]], $sheetDestination);
        $second->assertStatus(201);
        $this->assertSame('EXECUTED', $second->json('data.weaning_order.status'));
        $this->assertSame(1, Batch::where('name', 'Destete Dos Tandas WO')->count());
        $this->assertSame((int) $a->fresh()->batch_id, (int) $b->fresh()->batch_id);
    }

    public function test_a_calf_already_weaned_by_the_order_is_named_as_such(): void
    {
        $calf = $this->nursingCalf('WO-S3', 'M');
        $other = $this->nursingCalf('WO-S4', 'M');
        $id = $this->emitSingle([$calf, $other])->json('id');

        $this->scanSingle($id, [[$calf, 170.0]])->assertStatus(201);

        $again = $this->scanSingle($id, [[$calf, 170.0]]);
        $again->assertStatus(422);
        $this->assertSame('ALREADY_WEANED_BY_ORDER', $again->json('row_errors.0.errors.0.code'));
    }

    public function test_a_sheet_printed_blank_gets_a_registered_order_on_confirming(): void
    {
        $calf = $this->nursingCalf('WO-S5', 'M');

        $response = $this->scanSingle(null, [[$calf, 181.0]], ['tipo_destete' => 'ANTICIPADO']);

        $response->assertStatus(201);
        $this->assertTrue($response->json('data.weaning_order.created_from_sheet'));
        $this->assertSame('EXECUTED', $response->json('data.weaning_order.status'));
        $this->assertSame('REGISTERED', $response->json('data.weaning_order.kind'));
        $this->assertSame('ANTICIPATED', WeaningOrder::where('code', $response->json('data.weaning_order.code'))->value('weaning_type'));
    }

    public function test_a_code_that_resolves_to_no_order_is_rejected(): void
    {
        $calf = $this->nursingCalf('WO-S6', 'M');
        $before = WeaningOrder::count();

        $response = $this->scanSingle(null, [[$calf, 181.0]], ['orden_destete' => 'DS-19990101-0001']);

        $response->assertStatus(422);
        $this->assertSame('WEANING_ORDER_NOT_FOUND', $response->json('header_errors.0.code'));
        $this->assertSame($before, WeaningOrder::count());
    }

    public function test_a_blank_sheet_cannot_wean_a_calf_another_order_holds(): void
    {
        $calf = $this->nursingCalf('WO-S7', 'M');
        $code = $this->emitSingle([$calf])->json('code');

        $response = $this->scanSingle(null, [[$calf, 180.0]]);

        $response->assertStatus(422);
        $this->assertSame('ANIMAL_IN_OPEN_ORDER', $response->json('row_errors.0.errors.0.code'));
        $this->assertStringContainsString($code, $response->json('row_errors.0.errors.0.message'));
    }

    public function test_a_category_written_on_a_keep_order_is_warned_and_not_applied(): void
    {
        $calf = $this->nursingCalf('WO-S8', 'M');
        $id = $this->emitSingle([$calf])->json('id');

        $response = $this->scanSingle($id, [[$calf, 180.0, 'Novillito']]);

        $response->assertStatus(201);
        $this->assertContains('CATEGORY_CHANGE_NOT_EXPECTED', array_column($response->json('data.warnings'), 'code'));
        $this->assertSame($this->categoryId('TERNERO'), (int) $calf->fresh()->category_id);
    }

    public function test_the_category_written_at_the_chute_is_applied_on_an_at_chute_order(): void
    {
        $male = $this->nursingCalf('WO-S9', 'M');
        $female = $this->nursingCalf('WO-S10', 'H');
        $id = $this->emitSingle([$male, $female], ['category_mode' => 'AT_CHUTE'])->json('id');

        $this->scanSingle($id, [[$male, 190.0, 'Novillito'], [$female, 170.0, null]])->assertStatus(201);

        $this->assertSame($this->categoryId('NOVILLITO'), (int) $male->fresh()->category_id);
        $this->assertSame($this->categoryId('TERNERO'), (int) $female->fresh()->category_id);

        // The order keeps what was decided at the chute: the new C/S, or none when it did not change.
        $lines = \Illuminate\Support\Facades\DB::table('weaning_order_animals')->where('weaning_order_id', $id)->pluck('target_category_id', 'caravan_id');
        $this->assertSame($this->categoryId('NOVILLITO'), (int) $lines[$male->id]);
        $this->assertNull($lines[$female->id]);
    }

    public function test_a_per_animal_sheet_row_without_its_batch_is_a_calf_without_destination(): void
    {
        $withBatch = $this->nursingCalf('WO-S11', 'M');
        $withoutBatch = $this->nursingCalf('WO-S12', 'H');

        $response = $this->apiAs('POST', '/work-templates/dest-01/process', [
            'fecha_destete' => now()->toDateString(),
            'destination_mode' => 'per_animal',
            'destinations' => [['key' => 'Destete Existente WO', 'target_batch_id' => $this->weaningBatch->id, 'new_batch' => null]],
            'rows' => [
                ['caravana' => 'WO-S11', 'peso' => 170, 'destination_key' => 'Destete Existente WO'],
                ['caravana' => 'WO-S12', 'peso' => 165, 'destination_key' => ''],
            ],
        ]);

        $response->assertStatus(422);
        $this->assertSame(1, $response->json('row_errors.0.row_index'));
        $this->assertSame('DESTINATION_MISSING', $response->json('row_errors.0.errors.0.code'));
        $this->assertTrue($this->isNursing($withBatch));
    }

    public function test_a_per_animal_sheet_creates_a_new_batch_with_the_m_letter_of_its_rows(): void
    {
        $this->nursingCalf('WO-S13', 'M');
        $this->nursingCalf('WO-S14', 'H');

        $response = $this->apiAs('POST', '/work-templates/dest-01/process', [
            'fecha_destete' => now()->toDateString(),
            'destination_mode' => 'per_animal',
            'destinations' => [
                ['key' => 'Destete Existente WO', 'target_batch_id' => $this->weaningBatch->id, 'new_batch' => null],
                ['key' => 'Destete Corral WO', 'target_batch_id' => null, 'new_batch' => ['name' => 'Destete Corral WO']],
            ],
            'rows' => [
                ['caravana' => 'WO-S13', 'peso' => 170, 'destination_key' => 'Destete Existente WO'],
                ['caravana' => 'WO-S14', 'peso' => 165, 'destination_key' => 'Destete Corral WO', 'manejo' => 'C'],
            ],
        ]);

        $response->assertStatus(201);
        $this->assertCount(2, $response->json('data.destinations'));
        $this->assertSame('per_animal', WeaningOrder::where('code', $response->json('data.weaning_order.code'))->value('destination_mode'));
        $this->assertTrue((bool) Batch::where('name', 'Destete Corral WO')->value('is_confined'));
    }

    public function test_an_unreadable_weaning_type_is_marked_on_its_cell(): void
    {
        $calf = $this->nursingCalf('WO-S15', 'M');

        $response = $this->scanSingle(null, [[$calf, 180.0]], ['tipo_destete' => 'ANTICIPADO, PRECOZ']);

        $response->assertStatus(422);
        $this->assertSame('tipo_destete', $response->json('header_errors.0.field'));
        $this->assertSame('WEANING_TYPE_UNKNOWN', $response->json('header_errors.0.code'));
    }
}
