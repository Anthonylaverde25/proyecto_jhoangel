<?php

declare(strict_types=1);

namespace Tests\Feature\EntryOrders;

use App\Models\EntryOrderArrivalFinding;

/**
 * What the chute saw on an animal as it came off the truck — an injured eye, a damaged ear, a limb
 * problem (APLOMO) — is a box on its reception line, by hand or on an ING-03. It is kept on the
 * reception, with the sheet that recorded it, and raises no incident.
 */
class ArrivalFindingsReceptionTest extends EntryOrderTestCase
{
    /**
     * @return array<string, mixed>
     */
    private function orderInTransit(int $head = 3): array
    {
        $order = $this->createOrder([
            'head_count' => $head,
            'sex_composition' => 'MALE',
            'male_count' => null,
            'female_count' => null,
            'breeds' => [['breed_id' => $this->breedId('Angus'), 'color_id' => $this->colorId('Negro')]],
        ])->json('order');

        return $this->loadDte($order['id'], $head, ['dte_number' => 'DTE-L']);
    }

    public function test_the_boxes_marked_by_hand_are_kept_on_the_reception(): void
    {
        $order = $this->orderInTransit();

        $received = $this->receiveOn($order, [
            ['caravana' => 'EO-L-1', 'arrival_findings' => ['EYE', 'LIMB', 'EYE']],
            ['caravana' => 'EO-L-2', 'arrival_findings' => []],
            ['caravana' => 'EO-L-3'],
        ]);

        $this->assertSame([['EYE', 'LIMB'], [], []], array_column($received['dtes'][0]['animals'], 'arrival_findings'));
        $this->assertSame(['EYE' => 1, 'EAR' => 0, 'LIMB' => 1], $received['dtes'][0]['arrival_findings_count']);
        // An injury is recorded, not settled with the provider.
        $this->assertSame([], $received['incidents']);

        $finding = EntryOrderArrivalFinding::where('finding', 'EYE')->firstOrFail();
        $this->assertSame(now()->toDateString(), $finding->observed_at->toDateString());
        $this->assertNull($finding->entry_order_receipt_sheet_id);
    }

    public function test_a_scanned_sheet_records_which_sheet_saw_it(): void
    {
        $order = $this->orderInTransit();
        $sheetId = $this->apiAs('POST', "/entry-orders/{$order['id']}/receipt-sheets", ['dte_id' => $order['dtes'][0]['id']])->json('order.receipt_sheets.0.id');

        $this->receive($order['id'], [
            'method' => 'SHEET',
            'receipt_sheet_id' => $sheetId,
            'animals' => [['caravana' => 'EO-L-1', 'arrival_findings' => ['EAR']]],
        ])->assertOk();

        $this->assertSame($sheetId, EntryOrderArrivalFinding::where('finding', 'EAR')->value('entry_order_receipt_sheet_id'));
    }

    public function test_an_unknown_finding_is_refused(): void
    {
        $order = $this->orderInTransit();

        $this->receive($order['id'], ['dte_id' => $order['dtes'][0]['id'], 'animals' => [['caravana' => 'EO-L-1', 'arrival_findings' => ['TAIL']]]])
            ->assertStatus(422);
    }
}
