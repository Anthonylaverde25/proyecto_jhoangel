<?php

declare(strict_types=1);

namespace Tests\Feature\Insemination;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** GET /inseminations: which animals were inseminated, across every AI order (assistant and MCP). */
class ListInseminationsTest extends InseminationTestCase
{
    public function test_filters_and_totals_across_orders(): void
    {
        [$a, $b] = $this->cows(2);
        $orderId = (int) $this->startAi([$a, $b])->assertStatus(201)->json('id');
        $this->inseminate($orderId, [
            $this->row($a, $this->lotA, date: now()->subDays(15)->toDateString()),
            $this->row($b, $this->lotB, 2, date: now()->subDays(14)->toDateString()),
        ])->assertStatus(201);
        $this->inseminate($orderId, [$this->row($a, $this->lotA, date: now()->subDays(3)->toDateString())])->assertStatus(201);

        $all = $this->apiAs('GET', "/inseminations?service_order_id={$orderId}")->assertOk();
        $all->assertJsonPath('total', 3)->assertJsonPath('females', 2)->assertJsonPath('straws', 4);
        $this->assertSame($a->identification, $all->json('data.0.identification'), 'newest first');
        $this->assertSame('L-A', $all->json('data.0.semen_lot.code'));

        $this->apiAs('GET', "/inseminations?semen_lot_id={$this->lotB}")->assertOk()->assertJsonPath('total', 1);
        $this->apiAs('GET', "/inseminations?item_id={$this->semenItem}&from=" . now()->subDays(5)->toDateString())->assertOk()->assertJsonPath('total', 1);
        $this->apiAs('GET', "/inseminations?batch_id={$this->rodeo->id}&caravan=" . substr($b->identification, 3))->assertOk()->assertJsonPath('total', 1);
        $sire = (int) DB::table('item_semen_details')->where('item_id', $this->semenItem)->value('semen_sire_id');
        $this->apiAs('GET', "/inseminations?semen_sire_id={$sire}&service_order_id={$orderId}")->assertOk()->assertJsonPath('total', 3);

        // Voided ones stay out unless asked for.
        $id = (int) DB::table('service_order_inseminations')->where('female_caravan_id', $b->id)->value('id');
        $this->apiAs('POST', "/service-orders/{$orderId}/inseminations/{$id}/void", ['reason' => 'Error', 'idempotency_key' => (string) Str::uuid()])->assertOk();
        $this->apiAs('GET', "/inseminations?service_order_id={$orderId}")->assertOk()->assertJsonPath('total', 2)->assertJsonPath('females', 1);
        $this->apiAs('GET', "/inseminations?service_order_id={$orderId}&include_voided=1")->assertOk()->assertJsonPath('total', 3);
    }

    public function test_an_mcp_read_token_can_list_them(): void
    {
        [$a] = $this->cows(1);
        $orderId = (int) $this->startAi([$a])->assertStatus(201)->json('id');
        $this->inseminate($orderId, [$this->row($a, $this->lotA)])->assertStatus(201);

        // A connection as the user creates it (manual MCP token, ability mcp:read).
        $token = (string) $this->apiAs('POST', '/mcp/connections', ['name' => 'Claude test', 'ttl_days' => 30])
            ->assertCreated()->json('meta.plain_text_token');
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->withHeaders(['Authorization' => 'Bearer ' . $token, 'X-Company-ID' => (string) $this->company->id])
            ->getJson($this->url("/inseminations?service_order_id={$orderId}"))
            ->assertOk()
            ->assertJsonPath('total', 1);
    }
}
