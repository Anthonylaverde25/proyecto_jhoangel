<?php

declare(strict_types=1);

namespace Tests\Feature\ExitOrders;

/**
 * An issued order executed in several loads, closed with animals pending, cancelled before
 * anything left, edited while nothing left, and its DTE loaded days later (01 §3, §6).
 */
class IssueAndExecuteTest extends ExitOrderTestCase
{
    public function test_an_order_is_executed_in_two_loads(): void
    {
        [$a, $b, $c] = [$this->steer('EX-I1'), $this->steer('EX-I2'), $this->steer('EX-I3')];

        $id = $this->issue([$a, $b, $c])->assertStatus(201)
            ->assertJsonPath('status', 'ISSUED')
            ->assertJsonPath('kind', 'PLANNED')
            ->assertJsonPath('pending_count', 3)
            ->json('id');

        $this->execute($id, [$this->line($a), $this->line($b)])
            ->assertOk()->assertJsonPath('status', 'PARTIAL')->assertJsonPath('pending_count', 1);
        $this->assertSame($this->breedingA->id, (int) $c->fresh()->batch_id);

        $this->execute($id, [$this->line($c)])
            ->assertOk()->assertJsonPath('status', 'EXECUTED')->assertJsonPath('exited_count', 3);
    }

    public function test_an_animal_outside_the_roll_does_not_leave_by_the_order(): void
    {
        $inRoll = $this->steer('EX-I4');
        $outside = $this->steer('EX-I5');
        $id = $this->issue([$inRoll])->json('id');

        $this->execute($id, [$this->line($outside)])
            ->assertStatus(422)->assertJsonValidationErrors('lines.0.caravan_id');
    }

    public function test_closing_incomplete_frees_the_pending_animals(): void
    {
        [$gone, $stayed] = [$this->steer('EX-I6'), $this->steer('EX-I7')];
        $id = $this->issue([$gone, $stayed])->json('id');

        $this->apiAs('POST', "/exit-orders/{$id}/close", ['reason' => 'No subió al camión'])
            ->assertStatus(422)->assertJsonValidationErrors('status');

        $this->execute($id, [$this->line($gone)])->assertOk();
        $this->apiAs('POST', "/exit-orders/{$id}/close", ['reason' => 'No subió al camión'])
            ->assertOk()
            ->assertJsonPath('status', 'CLOSED_INCOMPLETE')
            ->assertJsonPath('skipped_count', 1)
            ->assertJsonPath('animals.1.skipped_reason', 'No subió al camión');

        $this->issue([$stayed])->assertStatus(201);
    }

    public function test_an_order_is_cancelled_only_while_nothing_left(): void
    {
        [$a, $b] = [$this->steer('EX-I8'), $this->steer('EX-I9')];
        $first = $this->issue([$a])->json('id');
        $second = $this->issue([$b])->json('id');

        $this->apiAs('POST', "/exit-orders/{$first}/cancel", ['reason' => 'Se cayó la venta'])
            ->assertOk()->assertJsonPath('status', 'CANCELLED');
        $this->issue([$a])->assertStatus(201);

        $this->execute($second, [$this->line($b)])->assertOk();
        $this->apiAs('POST', "/exit-orders/{$second}/cancel", ['reason' => 'Error'])
            ->assertStatus(422)->assertJsonValidationErrors('status');
    }

    public function test_the_roll_is_edited_while_nothing_left(): void
    {
        [$a, $b] = [$this->steer('EX-I10'), $this->steer('EX-I11')];
        $id = $this->issue([$a])->json('id');

        $this->apiAs('PUT', "/exit-orders/{$id}", [
            'buyer_partner_id' => $this->buyer->id,
            'exit_date' => now()->toDateString(),
            'caravan_ids' => [$a->id, $b->id],
        ])->assertOk()->assertJsonPath('planned_head_count', 2);

        $this->execute($id, [$this->line($a)])->assertOk();

        $this->apiAs('PUT', "/exit-orders/{$id}", [
            'buyer_partner_id' => $this->buyer->id,
            'exit_date' => now()->toDateString(),
            'caravan_ids' => [$b->id],
        ])->assertStatus(422);
    }

    public function test_a_late_dte_goes_on_a_closed_order_but_never_on_a_cancelled_one(): void
    {
        $sold = $this->steer('EX-I12');
        $id = $this->registerExit([$this->line($sold)])->json('id');

        $this->assertSame([$id], array_column($this->apiAs('GET', '/exit-orders?without_dte=1')->json(), 'id'));

        $dteId = $this->apiAs('POST', "/exit-orders/{$id}/dtes", ['dte_number' => 'DTE-TARDE-1', 'dte_date' => now()->toDateString(), 'head_count' => 1])
            ->assertStatus(201)->assertJsonPath('without_dte', false)->json('dtes.0.id');
        $this->assertSame([], $this->apiAs('GET', '/exit-orders?without_dte=1')->json());

        $this->apiAs('DELETE', "/exit-orders/{$id}/dtes/{$dteId}")->assertNoContent();

        $cancelled = $this->issue([$this->steer('EX-I13')])->json('id');
        $this->apiAs('POST', "/exit-orders/{$cancelled}/cancel", ['reason' => 'No va'])->assertOk();
        $this->apiAs('POST', "/exit-orders/{$cancelled}/dtes", ['dte_number' => 'DTE-TARDE-2', 'dte_date' => now()->toDateString(), 'head_count' => 1])
            ->assertStatus(422)->assertJsonValidationErrors('dte_number');
    }
}
