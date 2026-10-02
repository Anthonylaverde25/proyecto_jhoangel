<?php

declare(strict_types=1);

namespace Tests\Feature\EntryOrders;

use App\Models\Batch;
use App\Models\EntryOrder;

/**
 * An entry order is born without caravans: confirmed, it creates its batch empty and waits for the
 * DTE. Drafts are kept without a batch until the purchase is confirmed.
 */
class EntryOrderLifecycleTest extends EntryOrderTestCase
{
    public function test_a_confirmed_purchase_waits_for_its_dte_with_an_empty_external_batch(): void
    {
        $response = $this->createOrder()->assertCreated();

        $order = $response->json('order');
        $this->assertSame('AWAITING_DTE', $order['status']);
        $this->assertSame('En espera de DTE', $order['status_label']);
        $this->assertMatchesRegularExpression('/^EN-' . now()->format('Ymd') . '-\d{4}$/', $order['code']);
        $this->assertSame(0, $order['entered_count']);
        $this->assertSame(40, $order['pending_count']);
        $this->assertSame([], $order['dtes']);

        $batch = Batch::findOrFail($order['batch']['id']);
        $this->assertSame('338-' . $order['number'], $batch->name);
        $this->assertSame($this->farm->id, (int) $batch->farm_id);
        $this->assertSame(0, (int) $batch->caravans_count);
        $this->assertTrue((bool) $batch->knows_to_eat);
        $this->assertEquals(160.0, (float) $batch->min_weight);
        $this->assertNull($batch->age_in_months);

        $this->assertSame(['A', 'B'], array_column($order['breeds'], 'letter'));
        $this->assertSame('9/10', $order['age_range']);
    }

    public function test_the_external_batch_shows_the_order_it_is_waiting_for(): void
    {
        $order = $this->createOrder()->json('order');

        $batch = collect($this->apiAs('GET', '/batches')->assertOk()->json())
            ->firstWhere('id', $order['batch']['id']);

        $this->assertSame($order['code'], $batch['entry_order']['code']);
        $this->assertSame('AWAITING_DTE', $batch['entry_order']['status']);
        $this->assertSame(40, $batch['entry_order']['head_count']);
        $this->assertSame(0, $batch['entry_order']['entered_count']);
        $this->assertSame($this->provider->id, $batch['provider_id']);
    }

    public function test_a_draft_has_no_batch_until_the_purchase_is_confirmed(): void
    {
        $draft = $this->createOrder([], false)->assertCreated()->json('order');

        $this->assertSame('DRAFT', $draft['status']);
        $this->assertNull($draft['batch']);
        $this->assertSame(0, Batch::where('name', '338-' . $draft['number'])->count());

        $confirmed = $this->apiAs('POST', "/entry-orders/{$draft['id']}/confirm")->assertOk()->json('order');

        $this->assertSame('AWAITING_DTE', $confirmed['status']);
        $this->assertNotNull($confirmed['batch']);
        $this->assertSame($draft['code'], $confirmed['code']);
    }

    public function test_a_draft_can_be_rewritten_and_keeps_its_number(): void
    {
        $draft = $this->createOrder([], false)->json('order');

        $updated = $this->apiAs('PUT', "/entry-orders/{$draft['id']}", [
            ...$this->troop(['head_count' => 30, 'male_count' => 30, 'female_count' => null, 'sex_composition' => 'MALE', 'auction_number' => '412']),
            'confirm' => false,
        ])->assertOk()->json('order');

        $this->assertSame('DRAFT', $updated['status']);
        $this->assertSame(30, $updated['head_count']);
        $this->assertSame('MALE', $updated['sex_composition']);
        $this->assertNull($updated['male_count']);
        $this->assertSame('412-' . $draft['number'], $updated['batch_name']);
        $this->assertSame($draft['number'], $updated['number']);
    }

    public function test_a_confirmed_order_is_no_longer_editable(): void
    {
        $order = $this->createOrder()->json('order');

        $this->apiAs('PUT', "/entry-orders/{$order['id']}", [...$this->troop(), 'confirm' => false])
            ->assertStatus(422)
            ->assertJsonPath('code', 'NOT_EDITABLE');
    }

    public function test_numbers_are_correlative_and_codes_sequential_per_day(): void
    {
        $first = $this->createOrder()->json('order');
        $second = $this->createOrder(['auction_number' => '339'])->json('order');

        $this->assertSame($first['number'] + 1, $second['number']);
        $this->assertSame((int) substr($first['code'], -4) + 1, (int) substr($second['code'], -4));
        $this->assertSame('339-' . $second['number'], $second['batch_name']);
    }

    public function test_a_draft_is_discarded_without_reason_and_a_waiting_order_needs_one(): void
    {
        $draft = $this->createOrder([], false)->json('order');
        $this->apiAs('POST', "/entry-orders/{$draft['id']}/cancel")->assertOk()->assertJsonPath('status', 'CANCELLED');

        $order = $this->createOrder()->json('order');
        $this->apiAs('POST', "/entry-orders/{$order['id']}/cancel", ['reason' => ''])->assertStatus(422);

        $cancelled = $this->apiAs('POST', "/entry-orders/{$order['id']}/cancel", ['reason' => 'Se cayó la compra'])
            ->assertOk()
            ->json();

        $this->assertSame('CANCELLED', $cancelled['status']);
        $this->assertSame('Se cayó la compra', $cancelled['closing_reason']);
        // The batch never had animals: it leaves the list instead of staying there empty.
        $this->assertFalse((bool) Batch::findOrFail($order['batch']['id'])->is_active);
    }

    public function test_a_draft_is_not_printed_and_a_confirmed_order_is_stamped_once(): void
    {
        $draft = $this->createOrder([], false)->json('order');
        $this->apiAs('POST', "/entry-orders/{$draft['id']}/printed")
            ->assertStatus(422)
            ->assertJsonPath('code', 'DRAFT_NOT_PRINTABLE');

        $order = $this->createOrder()->json('order');
        $first = $this->apiAs('POST', "/entry-orders/{$order['id']}/printed")->assertOk()->json('printed_at');
        $second = $this->apiAs('POST', "/entry-orders/{$order['id']}/printed")->assertOk()->json('printed_at');

        $this->assertNotNull($first);
        $this->assertSame($first, $second);
    }

    public function test_the_code_read_off_paper_is_cleaned_before_looking_it_up(): void
    {
        $order = $this->createOrder()->json('order');
        $misread = str_replace(['0', '1'], ['O', 'I'], substr($order['code'], 3));

        $this->apiAs('GET', '/entry-orders/by-code/EN-' . $misread)
            ->assertOk()
            ->assertJsonPath('id', $order['id']);
    }

    public function test_the_list_filters_by_status(): void
    {
        $before = count($this->apiAs('GET', '/entry-orders?status=awaiting_dte')->json());
        $total = EntryOrder::count();

        $this->createOrder([], false);
        $this->createOrder();

        $waiting = $this->apiAs('GET', '/entry-orders?status=awaiting_dte')->assertOk()->json();

        $this->assertCount($before + 1, $waiting);
        $this->assertSame(['AWAITING_DTE'], array_values(array_unique(array_column($waiting, 'status'))));
        $this->assertSame($total + 2, EntryOrder::count());
    }

    public function test_the_next_number_previews_the_automatic_name(): void
    {
        $next = $this->apiAs('GET', '/entry-orders/next-number')->json('number');
        $order = $this->createOrder()->json('order');

        $this->assertSame($next, $order['number']);
        $this->assertSame('338-' . $next, $order['batch_name']);
        $this->assertSame($next + 1, $this->apiAs('GET', '/entry-orders/next-number')->json('number'));
    }
}
