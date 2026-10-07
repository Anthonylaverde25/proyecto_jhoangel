<?php

declare(strict_types=1);

namespace Tests\Feature\EntryOrders;

/**
 * "Corregir cabezas": a DTE loaded with the wrong head is corrected, with a reason, never below
 * what was already received or declared missing on it. The order follows; open incidents do not
 * close by themselves.
 */
class EntryOrderDteCorrectionTest extends EntryOrderTestCase
{
    /**
     * @param array<string, mixed> $body
     */
    private function correct(array $order, array $body, int $dteIndex = 0)
    {
        return $this->apiAs('PATCH', "/entry-orders/{$order['id']}/dtes/{$order['dtes'][$dteIndex]['id']}", $body);
    }

    /**
     * @return array<string, mixed>
     */
    private function maleOrder(int $head): array
    {
        return $this->createOrder(['head_count' => $head, 'sex_composition' => 'MALE', 'male_count' => null, 'female_count' => null])->json('order');
    }

    public function test_lowering_the_head_to_what_arrived_completes_the_order(): void
    {
        $order = $this->maleOrder(50);
        $loaded = $this->loadDte($order['id'], 52, ['dte_number' => 'DTE-FIX']);
        $received = $this->receiveOn($loaded, $this->animals('F', 50));
        $this->assertSame('IN_TRANSIT', $received['status']);

        $corrected = $this->correct($received, ['head_count' => 50, 'reason' => 'Se cargó 52 por error'])->assertOk()->json('order');

        $this->assertSame('COMPLETED', $corrected['status']);
        $this->assertSame(50, $corrected['dtes'][0]['head_count']);
        $this->assertSame(0, $corrected['in_transit_count']);

        $history = collect($corrected['history'])->last();
        $this->assertSame('dte_head_count_corrected', $history['metadata']['action']);
        $this->assertSame(52, $history['metadata']['from']);
        $this->assertSame(50, $history['metadata']['to']);
        $this->assertSame('Se cargó 52 por error', $history['reason']);
    }

    public function test_the_head_cannot_go_below_what_was_received_or_declared_missing(): void
    {
        $order = $this->maleOrder(5);
        $loaded = $this->loadDte($order['id'], 5);
        $received = $this->receiveOn($loaded, $this->animals('B', 3), 0, ['missing_head_count' => 1, 'reason' => 'Murió']);

        $this->correct($received, ['head_count' => 3, 'reason' => 'Error'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'HEAD_COUNT_BELOW_ACCOUNTED');

        $this->correct($received, ['head_count' => 5, 'reason' => 'Error'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'HEAD_COUNT_UNCHANGED');

        $this->correct($received, ['head_count' => 4])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        // 3 received + 1 missing: 4 is the floor. Nothing is left in transit, and the fifth head
        // bought now has no DTE: the order waits for documents again.
        $corrected = $this->correct($received, ['head_count' => 4, 'reason' => 'Eran 4'])->assertOk()->json('order');
        $this->assertSame(0, $corrected['in_transit_count']);
        $this->assertSame(1, $corrected['pending_dte_count']);
        $this->assertSame('AWAITING_DTE', $corrected['status']);
    }

    public function test_raising_the_head_over_the_purchase_opens_an_excess(): void
    {
        $order = $this->maleOrder(2);
        $loaded = $this->loadDte($order['id'], 2);

        $response = $this->correct($loaded, ['head_count' => 3, 'reason' => 'El DTE dice 3'])->assertOk();

        $this->assertSame(['EXCESS_HEAD'], array_column($response->json('order.incidents'), 'type'));
        $this->assertContains('EXCESS_HEAD', array_column($response->json('warnings'), 'code'));
        $this->assertSame(3, $response->json('order.in_transit_count'));
    }

    public function test_an_incident_the_correction_leaves_without_difference_stays_open_and_is_reported(): void
    {
        $order = $this->maleOrder(3);
        $loaded = $this->loadDte($order['id'], 2);
        $this->loadDte($order['id'], 1);
        $received = $this->receiveOn($loaded, $this->animals('X', 3));
        $this->assertSame(['ARRIVAL_EXCESS'], array_column($received['incidents'], 'type'));

        $response = $this->correct($received, ['head_count' => 3, 'reason' => 'El DTE decía 3'])->assertOk();
        $warnings = array_column($response->json('warnings'), 'code');

        $this->assertContains('INCIDENT_NO_LONGER_DIFFERS', $warnings);
        $this->assertSame('OPEN', $response->json('order.incidents.0.status'));
    }

    public function test_a_sheet_issued_for_the_old_head_is_reported_outdated(): void
    {
        $order = $this->maleOrder(4);
        $loaded = $this->loadDte($order['id'], 4);
        $this->apiAs('POST', "/entry-orders/{$order['id']}/receipt-sheets", ['dte_id' => $loaded['dtes'][0]['id']])->assertCreated();

        $response = $this->correct($loaded, ['head_count' => 3, 'reason' => 'Eran 3'])->assertOk();

        $this->assertContains('RECEIPT_SHEET_OUTDATED', array_column($response->json('warnings'), 'code'));
        $this->assertTrue($response->json('order.receipt_sheets.0.outdated'));
    }

    public function test_a_closed_order_is_not_corrected(): void
    {
        $order = $this->maleOrder(1);
        $done = $this->receiveOn($this->loadDte($order['id'], 1), $this->animals('C', 1));
        $this->assertFalse($done['can_correct_dtes']);

        $this->correct($done, ['head_count' => 2, 'reason' => 'Tarde'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'ORDER_NOT_RECEIVING');
    }
}
