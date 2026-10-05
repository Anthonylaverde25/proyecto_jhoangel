<?php

declare(strict_types=1);

namespace Tests\Feature\BirthOrders;

use App\Models\BirthOrder;
use App\Models\Caravan;
use App\Models\CaravanGestation;
use App\Models\CaravanLineage;
use App\Models\CaravanWeight;

/**
 * A birth order is issued before the calving rounds and fulfilled over several of them.
 */
class BirthOrderLifecycleTest extends BirthOrderTestCase
{
    public function test_an_order_is_fulfilled_over_several_rounds(): void
    {
        $a = $this->pregnantFemale('BO-A1');
        $b = $this->pregnantFemale('BO-A2', $this->breedingB);
        $c = $this->pregnantFemale('BO-A3');

        $order = $this->emit([$a, $b, $c])->assertStatus(201);
        $orderId = (int) $order->json('id');
        $this->assertMatchesRegularExpression('/^PA-\d{8}-\d{4}$/', (string) $order->json('code'));
        $this->assertSame('ISSUED', $order->json('status'));
        $this->assertSame(3, $order->json('planned_head_count'));

        // Round 1: one calved, the others did not yet.
        $this->scan($orderId, [
            $this->liveRow($a, 'BO-C1', 'M', 34.5),
            ['caravana_madre' => $b->identification],
            ['caravana_madre' => $c->identification],
        ])->assertStatus(201)->assertJsonPath('data.birth_order.status', 'PARTIAL');

        // Round 2: a calf born dead and one that died at foot close the order.
        $this->scan($orderId, [
            ['caravana_madre' => $b->identification, 'resultado' => 'NM', 'fecha_nacimiento' => now()->toDateString()],
            ['caravana_madre' => $c->identification, 'resultado' => 'M', 'fecha_nacimiento' => now()->toDateString()],
        ])->assertStatus(201)->assertJsonPath('data.birth_order.status', 'EXECUTED');

        $saved = $this->apiAs('GET', "/birth-orders/{$orderId}")->assertOk();
        $this->assertSame(1, $saved->json('born_head_count'));
        $this->assertSame(1, $saved->json('stillborn_head_count'));
        $this->assertSame(1, $saved->json('born_died_head_count'));

        // The calf was born in its mother's batch, weighed, with lineage.
        $calf = Caravan::where('identification', 'BO-C1')->firstOrFail();
        $this->assertSame($this->breedingA->id, (int) $calf->batch_id);
        $this->assertSame(0, (int) $calf->teeth);
        $this->assertEquals(34.5, (float) CaravanWeight::where('caravan_id', $calf->id)->where('current', true)->value('weight'));
        $this->assertSame($a->id, (int) CaravanLineage::where('caravan_id', $calf->id)->value('mother_id'));

        // Every gestation is closed: the stillbirth as a loss of the mother, the death at foot as a
        // successful calving.
        $this->assertNull($this->currentGestation($a));
        $lostB = CaravanGestation::where('caravan_id', $b->id)->latest('id')->firstOrFail();
        $this->assertFalse((bool) $lostB->success);
        $this->assertTrue((bool) CaravanGestation::where('caravan_id', $c->id)->latest('id')->value('success'));

        // The three are full-term calvings: the heifers become cows.
        $this->assertSame($this->categoryId('VACA'), (int) $a->fresh()->category_id);
        $this->assertSame($this->categoryId('VACA'), (int) $b->fresh()->category_id);
        $this->assertSame($this->categoryId('VACA'), (int) $c->fresh()->category_id);
    }

    public function test_a_female_cannot_be_in_two_open_birth_orders(): void
    {
        $a = $this->pregnantFemale('BO-B1');
        $this->emit([$a])->assertStatus(201);

        $this->emit([$a])->assertStatus(422)->assertJsonPath('code', 'ANIMAL_IN_OPEN_ORDER');

        // A draft holds nothing.
        $this->emit([$a], ['issue' => false])->assertStatus(201)->assertJsonPath('status', 'DRAFT');
    }

    public function test_only_pregnant_females_can_be_ordered(): void
    {
        $open = Caravan::create([
            'company_id' => $this->company->id,
            'batch_id' => $this->breedingA->id,
            'identification' => 'BO-EMPTY',
            'sex' => 'H',
            'category_id' => $this->categoryId('VACA'),
        ]);

        $this->emit([$open])->assertStatus(422)->assertJsonPath('code', 'NO_ACTIVE_GESTATION');
    }

    public function test_draft_issue_cancel_and_close_incomplete(): void
    {
        $a = $this->pregnantFemale('BO-D1');
        $b = $this->pregnantFemale('BO-D2');

        $draft = $this->emit([$a, $b], ['issue' => false])->assertStatus(201);
        $id = (int) $draft->json('id');

        $this->apiAs('POST', "/birth-orders/{$id}/printed")->assertStatus(422)->assertJsonPath('code', 'DRAFT_NOT_PRINTABLE');
        $this->apiAs('POST', "/birth-orders/{$id}/issue")->assertOk()->assertJsonPath('status', 'ISSUED');
        $this->apiAs('POST', "/birth-orders/{$id}/cancel", [])->assertStatus(422)->assertJsonPath('code', 'REASON_REQUIRED');

        $this->scan($id, [$this->liveRow($a, 'BO-DC1')])->assertStatus(201);
        $this->apiAs('POST', "/birth-orders/{$id}/cancel", ['reason' => 'x'])->assertStatus(422);

        $closed = $this->apiAs('POST', "/birth-orders/{$id}/close-incomplete", ['reason' => 'Fin de temporada'])->assertOk();
        $this->assertSame('CLOSED_INCOMPLETE', $closed->json('status'));
        $this->assertSame(1, $closed->json('skipped_head_count'));

        // Closing an order is not declaring a loss: the pending female is still pregnant.
        $this->assertNotNull($this->currentGestation($b));
    }

    public function test_a_blank_sheet_creates_a_registered_order(): void
    {
        $a = $this->pregnantFemale('BO-E1');
        $before = BirthOrder::count();

        $response = $this->scan(null, [$this->liveRow($a, 'BO-EC1', 'H')])->assertStatus(201);

        $this->assertSame($before + 1, BirthOrder::count());
        $this->assertSame('EXECUTED', $response->json('data.birth_order.status'));
        $this->assertSame('REGISTERED', $response->json('data.birth_order.kind'));
        $this->assertTrue($response->json('data.birth_order.created_from_sheet'));
    }

    public function test_a_code_that_does_not_resolve_is_refused(): void
    {
        $a = $this->pregnantFemale('BO-F1');

        $this->scan(null, [$this->liveRow($a, 'BO-FC1')], ['orden_paricion' => 'PA-20260101-0099'])
            ->assertStatus(422)
            ->assertJsonPath('header_errors.0.code', 'BIRTH_ORDER_NOT_FOUND');
    }
}
