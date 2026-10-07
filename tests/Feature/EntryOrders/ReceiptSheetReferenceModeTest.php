<?php

declare(strict_types=1);

namespace Tests\Feature\EntryOrders;

use App\Models\Caravan;

/**
 * An ING-03 asks for the breed, coat and category of each line written in words (by default) or
 * by the header's reference (letters and numbers). Like the weighing, it is part of the paper: it
 * changes only before printing, and a new sheet keeps it. A written line is resolved against the
 * order; a breed the purchase does not declare is received and reported.
 */
class ReceiptSheetReferenceModeTest extends EntryOrderTestCase
{
    /**
     * Braford Colorado and Brangus Negro, both sexes; Novillito and Torito for the males.
     *
     * @return array{order: array<string, mixed>, dte_id: int}
     */
    private function orderInTransit(int $head = 4): array
    {
        $order = $this->createOrder([
            'categories' => [
                ['category_id' => $this->categoryId('NOVILLITO'), 'head_count' => $head - 1],
                ['category_id' => $this->categoryId('TORITO'), 'head_count' => 1],
            ],
            'sex_composition' => 'MALE',
            'male_count' => null,
            'female_count' => null,
        ])->json('order');
        $loaded = $this->loadDte($order['id'], $head, ['dte_number' => 'DTE-W']);

        return ['order' => $loaded, 'dte_id' => $loaded['dtes'][0]['id']];
    }

    private function issue(int $orderId, int $dteId, array $extra = [])
    {
        return $this->apiAs('POST', "/entry-orders/{$orderId}/receipt-sheets", ['dte_id' => $dteId, ...$extra]);
    }

    private function configure(int $orderId, int $sheetId, array $payload)
    {
        return $this->apiAs('PATCH', "/entry-orders/{$orderId}/receipt-sheets/{$sheetId}", $payload);
    }

    /**
     * @param list<array<string, mixed>> $animals
     */
    private function scan(int $orderId, int $sheetId, array $animals)
    {
        return $this->receive($orderId, ['method' => 'SHEET', 'receipt_sheet_id' => $sheetId, 'animals' => $animals]);
    }

    public function test_a_first_sheet_is_written_in_words_and_a_new_one_keeps_the_mode(): void
    {
        ['order' => $order, 'dte_id' => $dteId] = $this->orderInTransit();

        $sheet = $this->issue($order['id'], $dteId)->assertCreated()->json('order.receipt_sheets.0');
        $this->assertSame('WRITTEN', $sheet['reference_mode']);

        $this->configure($order['id'], $sheet['id'], ['reference_mode' => 'CODE'])->assertOk();
        $sheets = $this->issue($order['id'], $dteId)->assertCreated()->json('order.receipt_sheets');

        $this->assertSame(['CODE', 'CODE'], array_column($sheets, 'reference_mode'));
    }

    public function test_the_mode_changes_until_the_sheet_is_printed(): void
    {
        ['order' => $order, 'dte_id' => $dteId] = $this->orderInTransit();
        $sheetId = $this->issue($order['id'], $dteId)->json('order.receipt_sheets.0.id');

        $changed = $this->configure($order['id'], $sheetId, ['reference_mode' => 'CODE'])->assertOk()->json();
        $this->assertSame('CODE', $changed['receipt_sheets'][0]['reference_mode']);
        $this->assertSame('receipt_sheet_reference_changed', collect($changed['history'])->last()['metadata']['action']);

        $this->apiAs('POST', "/entry-orders/{$order['id']}/receipt-sheets/{$sheetId}/printed")->assertOk();

        $this->configure($order['id'], $sheetId, ['reference_mode' => 'WRITTEN'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'RECEIPT_SHEET_ALREADY_PRINTED');
    }

    public function test_weighing_and_reference_change_together_in_one_history_line(): void
    {
        ['order' => $order, 'dte_id' => $dteId] = $this->orderInTransit();
        $sheetId = $this->issue($order['id'], $dteId)->json('order.receipt_sheets.0.id');

        $changed = $this->configure($order['id'], $sheetId, ['weighing_mode' => 'AVERAGE', 'reference_mode' => 'CODE'])->assertOk()->json();
        $last = collect($changed['history'])->last()['metadata'];

        $this->assertSame('receipt_sheet_configured', $last['action']);
        $this->assertSame(['AVERAGE', 'CODE'], [$last['weighing_mode'], $last['reference_mode']]);
    }

    public function test_written_breed_coat_and_category_are_resolved_against_the_order(): void
    {
        ['order' => $order, 'dte_id' => $dteId] = $this->orderInTransit();
        $sheetId = $this->issue($order['id'], $dteId)->json('order.receipt_sheets.0.id');

        $animals = $this->scan($order['id'], $sheetId, [
            ['caravana' => 'EO-W-1', 'breed_text' => 'Braford', 'color_text' => 'Col.', 'category_text' => 'Novillito'],
            ['caravana' => 'EO-W-2', 'breed_text' => 'brangus', 'category_text' => 'tor'],
        ])->assertOk()->json('order.dtes.0.animals');

        $this->assertSame([1, 2], array_column($animals, 'breed_position'));
        $this->assertSame([1, 2], array_column($animals, 'category_position'));
        $this->assertSame($this->colorId('Colorado'), Caravan::where('identification', 'EO-W-1')->value('color_id'));
    }

    public function test_an_ambiguous_or_unknown_cell_is_an_error_with_its_candidates(): void
    {
        ['order' => $order, 'dte_id' => $dteId] = $this->orderInTransit();
        $sheetId = $this->issue($order['id'], $dteId)->json('order.receipt_sheets.0.id');

        $errors = $this->scan($order['id'], $sheetId, [
            ['caravana' => 'EO-W-1', 'breed_text' => 'Bra', 'category_text' => 'Novillito'],
            ['caravana' => 'EO-W-2', 'breed_text' => 'Braford', 'category_text' => 'Vaca'],
        ])->assertStatus(422)->json('row_errors');

        $this->assertSame(['BREED_AMBIGUOUS', 'CATEGORY_UNKNOWN_TEXT'], array_column($errors, 'code'));
        $this->assertSame(['breed_text', 'category_text'], array_column($errors, 'field'));
        $this->assertSame(['Braford', 'Brangus'], $errors[0]['candidates']);
        $this->assertSame(0, Caravan::where('identification', 'like', 'EO-W-%')->count());
    }

    public function test_a_breed_the_purchase_does_not_declare_is_received_and_reported(): void
    {
        ['order' => $order, 'dte_id' => $dteId] = $this->orderInTransit();
        $sheetId = $this->issue($order['id'], $dteId)->json('order.receipt_sheets.0.id');

        $response = $this->scan($order['id'], $sheetId, [
            ['caravana' => 'EO-W-1', 'breed_text' => 'Angus', 'color_text' => 'Negro', 'category_text' => 'Novillito'],
        ])->assertOk();

        $this->assertSame(['BREED_NOT_IN_ORDER'], array_column($response->json('warnings'), 'code'));
        $this->assertSame(['BREED_MISMATCH'], array_column($response->json('order.incidents'), 'type'));
        $this->assertStringContainsString('EO-W-1 (Angus Negro)', $response->json('order.incidents.0.detail'));
        $this->assertNull($response->json('order.dtes.0.animals.0.breed_position'));
        $this->assertSame($this->breedId('Angus'), Caravan::where('identification', 'EO-W-1')->value('breed_id'));
    }

    public function test_a_line_with_the_breed_by_letter_and_in_words_is_a_conflict(): void
    {
        ['order' => $order, 'dte_id' => $dteId] = $this->orderInTransit();
        $sheetId = $this->issue($order['id'], $dteId)->json('order.receipt_sheets.0.id');

        $this->scan($order['id'], $sheetId, [
            ['caravana' => 'EO-W-1', 'breed_position' => 1, 'breed_text' => 'Brangus', 'category_position' => 1],
        ])->assertStatus(422)->assertJsonPath('row_errors.0.code', 'REFERENCE_CONFLICT');
    }
}
