<?php

declare(strict_types=1);

namespace Tests\Feature\EntryOrders;

use Tests\Feature\DryRun\AssertsDryRun;
use App\Models\Caravan;
use App\Models\CaravanBodyCondition;
use App\Models\CaravanWeight;

/**
 * ING-03: the receipt sheet of a DTE, an appendix of the ING-02. It is issued with a blank line per
 * head in transit plus free lines, a newer one replaces the one still out, and scanning it
 * receives what the paper says — page by page — creating a caravan for every line written. The
 * sheet is weighed per animal or with one average, and records each caravan's body condition (1
 * to 5).
 */
class EntryOrderReceiptSheetTest extends EntryOrderTestCase
{
    use AssertsDryRun;

    /**
     * @return array{order: array<string, mixed>, dte_id: int}
     */
    private function orderInTransit(int $head = 3, string $prefix = 'R'): array
    {
        $order = $this->createOrder([
            'head_count' => $head,
            'sex_composition' => 'MALE',
            'male_count' => null,
            'female_count' => null,
            'breeds' => [['breed_id' => $this->breedId('Angus'), 'color_id' => $this->colorId('Negro')]],
        ])->json('order');
        $loaded = $this->loadDte($order['id'], $head, ['dte_number' => "DTE-{$prefix}"]);

        return ['order' => $loaded, 'dte_id' => $loaded['dtes'][0]['id']];
    }

    private function issue(int $orderId, int $dteId, ?string $weighingMode = null)
    {
        return $this->apiAs('POST', "/entry-orders/{$orderId}/receipt-sheets", array_filter(['dte_id' => $dteId, 'weighing_mode' => $weighingMode]));
    }

    /**
     * @param list<array<string, mixed>> $animals
     * @param array<string, mixed> $extra
     */
    private function scan(int $orderId, int $sheetId, array $animals, array $extra = [])
    {
        return $this->receive($orderId, ['method' => 'SHEET', 'receipt_sheet_id' => $sheetId, 'animals' => $animals, ...$extra]);
    }

    public function test_a_sheet_has_a_blank_line_per_head_in_transit_and_counts_its_pages(): void
    {
        ['order' => $order, 'dte_id' => $dteId] = $this->orderInTransit(17);

        $response = $this->issue($order['id'], $dteId)->assertCreated();
        $sheet = $response->json('order.receipt_sheets.0');

        $this->assertSame(1, $response->json('sheet_number'));
        $this->assertSame('R1', $sheet['label']);
        $this->assertSame('ISSUED', $sheet['status']);
        $this->assertSame(17, $sheet['dte_head_count']);
        $this->assertSame(17, $sheet['expected_head_count']);
        // 17 head and 4 free lines do not fit in one page of 20.
        $this->assertSame(21, $sheet['row_count']);
        $this->assertSame(2, $sheet['page_count']);
        $this->assertSame([1, 2], $sheet['missing_pages']);
        $this->assertFalse($sheet['outdated']);
        $this->assertSame(17, collect($response->json('order.history'))->last()['metadata']['expected_head_count']);
    }

    public function test_a_later_sheet_only_expects_the_head_still_in_transit(): void
    {
        ['order' => $order, 'dte_id' => $dteId] = $this->orderInTransit(5);
        $this->receiveOn($order, $this->animals('R', 2));

        $sheet = $this->issue($order['id'], $dteId)->assertCreated()->json('order.receipt_sheets.0');

        $this->assertSame(3, $sheet['expected_head_count']);
        $this->assertSame(7, $sheet['row_count']);
    }

    public function test_a_dry_run_of_a_scanned_sheet_answers_as_the_real_one_and_saves_nothing(): void
    {
        ['order' => $order, 'dte_id' => $dteId] = $this->orderInTransit(3);
        $sheetId = $this->issue($order['id'], $dteId)->json('order.receipt_sheets.0.id');
        $reception = fn () => $this->scan($order['id'], $sheetId, $this->animals('DRY', 4));

        $preview = $this->assertDryRunLeavesNothing(
            ['caravans', 'entry_order_animals', 'entry_order_incidents', 'caravan_movements', 'entry_order_histories'],
            $reception,
            200
        );
        $this->assertSame('COMPLETED', $preview->json('order.status'));
        $this->assertSame(['ARRIVAL_EXCESS'], array_column($preview->json('warnings'), 'code'));
        $this->assertSame('ISSUED', $this->apiAs('GET', "/entry-orders/{$order['id']}")->json('receipt_sheets.0.status'));

        $this->assertSame('COMPLETED', $reception()->assertOk()->json('order.status'));
    }

    public function test_a_new_sheet_replaces_the_one_still_out(): void
    {
        ['order' => $order, 'dte_id' => $dteId] = $this->orderInTransit();

        $this->issue($order['id'], $dteId)->assertCreated();
        $sheets = $this->issue($order['id'], $dteId)->assertCreated()->json('order.receipt_sheets');

        $this->assertSame(['REPLACED', 'ISSUED'], array_column($sheets, 'status'));
        $this->assertSame(['R1', 'R2'], array_column($sheets, 'label'));
    }

    public function test_a_dte_without_head_in_transit_has_no_sheet(): void
    {
        ['order' => $order, 'dte_id' => $dteId] = $this->orderInTransit(1);
        $this->receiveOn($order, $this->animals('R', 1));

        $this->issue($order['id'], $dteId)->assertStatus(422)->assertJsonPath('code', 'DTE_NOTHING_PENDING');
    }

    public function test_printing_the_sheet_is_recorded(): void
    {
        ['order' => $order, 'dte_id' => $dteId] = $this->orderInTransit();
        $sheetId = $this->issue($order['id'], $dteId)->json('order.receipt_sheets.0.id');

        $printed = $this->apiAs('POST', "/entry-orders/{$order['id']}/receipt-sheets/{$sheetId}/printed")->assertOk()->json('receipt_sheets.0');

        $this->assertNotNull($printed['printed_at']);
    }

    public function test_the_scanned_sheet_receives_the_lines_written_and_declares_missing(): void
    {
        ['order' => $order, 'dte_id' => $dteId] = $this->orderInTransit(3);
        $sheetId = $this->issue($order['id'], $dteId)->json('order.receipt_sheets.0.id');

        $result = $this->scan($order['id'], $sheetId, [['caravana' => 'EO-R-1', 'weight' => 190]], [
            'pages' => [1],
            'missing_head_count' => 1,
            'reason' => 'Murió en el viaje',
        ])->assertOk()->json('order');

        $this->assertSame(1, $result['received_count']);
        $this->assertSame(1, $result['missing_count']);
        // The third head was left unwritten: it arrives later.
        $this->assertSame(1, $result['in_transit_count']);
        $this->assertSame('PROCESSED', $result['receipt_sheets'][0]['status']);
        $this->assertSame(['MISSING_HEAD'], array_column($result['incidents'], 'type'));
        $this->assertSame('SHEET', $result['dtes'][0]['animals'][0]['reception_method']);
        $this->assertNotNull(Caravan::where('identification', 'EO-R-1')->first());
    }

    public function test_an_animal_of_more_on_a_free_line_is_received_with_an_incident(): void
    {
        ['order' => $order, 'dte_id' => $dteId] = $this->orderInTransit(2);
        $sheetId = $this->issue($order['id'], $dteId)->json('order.receipt_sheets.0.id');

        $result = $this->scan($order['id'], $sheetId, $this->animals('R', 3))->assertOk()->json('order');

        $this->assertSame(3, $result['received_count']);
        $this->assertSame(['ARRIVAL_EXCESS'], array_column($result['incidents'], 'type'));
        $this->assertStringContainsString('(hoja R1): EO-R-3', $result['incidents'][0]['detail']);
    }

    public function test_a_sheet_scanned_in_parts_stays_partial(): void
    {
        ['order' => $order, 'dte_id' => $dteId] = $this->orderInTransit(17);
        $sheetId = $this->issue($order['id'], $dteId)->json('order.receipt_sheets.0.id');

        $sheet = $this->scan($order['id'], $sheetId, $this->animals('R', 1), ['pages' => [1]])->assertOk()->json('order.receipt_sheets.0');

        $this->assertSame('PARTIAL', $sheet['status']);
        $this->assertSame([2], $sheet['missing_pages']);
    }

    public function test_a_caravan_that_already_exists_is_a_misreading(): void
    {
        ['order' => $order, 'dte_id' => $dteId] = $this->orderInTransit(2);
        $other = $this->orderInTransit(1, 'Q')['order'];
        $this->receiveOn($other, $this->animals('Q', 1));
        $sheetId = $this->issue($order['id'], $dteId)->json('order.receipt_sheets.0.id');

        $response = $this->scan($order['id'], $sheetId, [['caravana' => 'EO-R-1'], ['caravana' => 'EO-Q-1']])->assertStatus(422);

        $this->assertSame('CARAVAN_EXISTS', $response->json('row_errors.0.code'));
        $this->assertSame(1, $response->json('row_errors.0.row'));
    }

    public function test_a_sheet_of_another_dte_is_refused(): void
    {
        $order = $this->createOrder(['head_count' => 4, 'sex_composition' => 'MALE', 'male_count' => null, 'female_count' => null])->json('order');
        $this->loadDte($order['id'], 2);
        $loaded = $this->loadDte($order['id'], 2);
        $sheetId = $this->issue($order['id'], $loaded['dtes'][0]['id'])->json('order.receipt_sheets.0.id');

        $this->receive($order['id'], ['method' => 'SHEET', 'receipt_sheet_id' => $sheetId, 'dte_id' => $loaded['dtes'][1]['id'], 'animals' => $this->animals('S', 1)])
            ->assertStatus(422)
            ->assertJsonPath('header_errors.0.code', 'SHEET_NOT_OF_DTE');
    }

    public function test_a_replaced_sheet_that_comes_back_filled_in_is_received_with_a_warning(): void
    {
        ['order' => $order, 'dte_id' => $dteId] = $this->orderInTransit(2);
        $oldId = $this->issue($order['id'], $dteId)->json('order.receipt_sheets.0.id');
        $this->issue($order['id'], $dteId)->assertCreated();

        $response = $this->scan($order['id'], $oldId, $this->animals('R', 1))->assertOk();

        $this->assertSame(['RECEIPT_SHEET_REPLACED'], array_column($response->json('warnings'), 'code'));
        $this->assertSame('REPLACED', $response->json('order.receipt_sheets.0.status'));
    }

    public function test_a_sheet_is_weighed_per_animal_unless_it_says_average(): void
    {
        ['order' => $order, 'dte_id' => $dteId] = $this->orderInTransit();

        $this->assertSame('INDIVIDUAL', $this->issue($order['id'], $dteId)->json('order.receipt_sheets.0.weighing_mode'));

        $sheet = $this->issue($order['id'], $dteId, 'AVERAGE')->assertCreated()->json('order.receipt_sheets.1');
        $this->assertSame('AVERAGE', $sheet['weighing_mode']);
        $this->assertSame('Peso promedio', $sheet['weighing_mode_label']);

        // A new sheet of the DTE weighs like the one it replaces.
        $this->assertSame('AVERAGE', $this->issue($order['id'], $dteId)->json('order.receipt_sheets.2.weighing_mode'));
    }

    public function test_the_weighing_changes_only_while_the_sheet_was_not_printed(): void
    {
        ['order' => $order, 'dte_id' => $dteId] = $this->orderInTransit();
        $sheetId = $this->issue($order['id'], $dteId)->json('order.receipt_sheets.0.id');

        $changed = $this->apiAs('PATCH', "/entry-orders/{$order['id']}/receipt-sheets/{$sheetId}", ['weighing_mode' => 'AVERAGE'])->assertOk();
        $this->assertSame('AVERAGE', $changed->json('receipt_sheets.0.weighing_mode'));
        $this->assertSame('receipt_sheet_weighing_changed', collect($changed->json('history'))->last()['metadata']['action']);

        $this->apiAs('POST', "/entry-orders/{$order['id']}/receipt-sheets/{$sheetId}/printed")->assertOk();

        $this->apiAs('PATCH', "/entry-orders/{$order['id']}/receipt-sheets/{$sheetId}", ['weighing_mode' => 'INDIVIDUAL'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'RECEIPT_SHEET_ALREADY_PRINTED');
    }

    public function test_an_average_weight_is_recorded_as_an_average_and_warned_once_out_of_range(): void
    {
        ['order' => $order, 'dte_id' => $dteId] = $this->orderInTransit(3);
        $sheetId = $this->issue($order['id'], $dteId, 'AVERAGE')->json('order.receipt_sheets.0.id');

        $response = $this->scan($order['id'], $sheetId, [
            ['caravana' => 'EO-R-1', 'weight' => 210],
            ['caravana' => 'EO-R-2', 'weight' => 210],
        ])->assertOk();

        $this->assertSame(['WEIGHT_OUT_OF_RANGE'], array_column($response->json('warnings'), 'code'));
        $this->assertStringContainsString('peso promedio de 210 kg', $response->json('warnings.0.message'));

        $weight = CaravanWeight::where('caravan_id', Caravan::where('identification', 'EO-R-1')->value('id'))->where('current', true)->sole();
        $this->assertSame('AVERAGE', $weight->method);
        $this->assertStringStartsWith('Peso promedio de ingreso', $weight->notes);
    }

    public function test_the_body_condition_of_each_caravan_is_recorded_with_its_sheet(): void
    {
        ['order' => $order, 'dte_id' => $dteId] = $this->orderInTransit(2);
        $sheetId = $this->issue($order['id'], $dteId)->json('order.receipt_sheets.0.id');

        $this->scan($order['id'], $sheetId, [
            ['caravana' => 'EO-R-1', 'weight' => 180, 'body_condition' => 3.5],
            ['caravana' => 'EO-R-2', 'weight' => 182],
        ])->assertOk();

        $score = CaravanBodyCondition::where('caravan_id', Caravan::where('identification', 'EO-R-1')->value('id'))->sole();
        $this->assertSame('3.5', $score->score);
        $this->assertTrue($score->current);
        $this->assertSame(CaravanBodyCondition::SOURCE_ENTRY_RECEPTION, $score->source);
        $this->assertSame($sheetId, $score->entry_order_receipt_sheet_id);
        $this->assertSame(0, CaravanBodyCondition::where('caravan_id', Caravan::where('identification', 'EO-R-2')->value('id'))->count(), 'A blank EC leaves the caravan as it was.');
    }

    public function test_a_body_condition_off_the_official_scale_is_an_error_on_its_line(): void
    {
        ['order' => $order, 'dte_id' => $dteId] = $this->orderInTransit(2);
        $sheetId = $this->issue($order['id'], $dteId)->json('order.receipt_sheets.0.id');

        $response = $this->scan($order['id'], $sheetId, [
            ['caravana' => 'EO-R-1', 'weight' => 180, 'body_condition' => 3.2],
            ['caravana' => 'EO-R-2', 'weight' => 180, 'body_condition' => 6],
        ])->assertStatus(422);

        $this->assertSame(['BODY_CONDITION_INVALID', 'BODY_CONDITION_INVALID'], array_column($response->json('row_errors'), 'code'));
        $this->assertSame(0, CaravanBodyCondition::count());
    }
}
