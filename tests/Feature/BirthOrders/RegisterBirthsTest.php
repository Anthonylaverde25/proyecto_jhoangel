<?php

declare(strict_types=1);

namespace Tests\Feature\BirthOrders;

use App\Models\BirthOrder;
use App\Models\Caravan;

/**
 * "Registrar partos" and "Ejecutar orden" from the screen: the same round as a sheet, through the
 * same processing.
 */
class RegisterBirthsTest extends BirthOrderTestCase
{
    public function test_registered_births_are_born_executed(): void
    {
        $a = $this->pregnantFemale('BO-M1');
        $b = $this->pregnantFemale('BO-M2', $this->breedingB);

        $response = $this->apiAs('POST', '/birth-orders/register', [
            'animals' => [
                ['caravan_id' => $a->id, 'outcome' => 'LIVE', 'calf_identification' => 'BO-MC1', 'calf_sex' => 'H', 'calf_weight' => 30, 'birth_date' => now()->toDateString()],
                ['caravan_id' => $b->id, 'outcome' => 'PERINATAL_DEATH', 'calf_sex' => 'M', 'birth_date' => now()->toDateString()],
            ],
        ])->assertStatus(201);

        $this->assertSame('EXECUTED', $response->json('order.status'));
        $this->assertSame('REGISTERED', $response->json('order.kind'));
        $this->assertSame(1, $response->json('data.live_count'));
        $this->assertSame(1, $response->json('data.perinatal_death_count'));
        $this->assertSame('BORN_DIED', collect($response->json('order.animals'))->firstWhere('caravan_id', $b->id)['status']);
        $this->assertSame('M', collect($response->json('order.animals'))->firstWhere('caravan_id', $b->id)['calf_sex']);
        $this->assertSame($this->breedingA->id, (int) Caravan::where('identification', 'BO-MC1')->value('batch_id'));
    }

    public function test_a_registration_leaves_nothing_pending_and_a_failure_leaves_no_order(): void
    {
        $a = $this->pregnantFemale('BO-N1');
        $before = BirthOrder::count();

        $this->apiAs('POST', '/birth-orders/register', [
            'animals' => [['caravan_id' => $a->id]],
        ])->assertStatus(422);

        // A calf tag already in use: the processing refuses it, and the order is rolled back.
        $this->apiAs('POST', '/birth-orders/register', [
            'animals' => [['caravan_id' => $a->id, 'outcome' => 'LIVE', 'calf_identification' => 'BO-N1', 'calf_sex' => 'M', 'birth_date' => now()->toDateString()]],
        ])->assertStatus(422)->assertJsonPath('row_errors.0.errors.0.code', 'CALF_TAG_IN_USE');

        $this->assertSame($before, BirthOrder::count());
    }

    public function test_a_calf_may_be_born_without_its_tag(): void
    {
        $a = $this->pregnantFemale('BO-U1');

        $response = $this->apiAs('POST', '/birth-orders/register', [
            'animals' => [['caravan_id' => $a->id, 'outcome' => 'LIVE', 'calf_sex' => 'H', 'calf_weight' => 31, 'birth_date' => now()->toDateString()]],
        ])->assertStatus(201);

        // The order is done: the tag is a later fact, followed from the batch.
        $this->assertSame('EXECUTED', $response->json('order.status'));
        $this->assertSame(1, $response->json('data.untagged_calves_count'));
        $this->assertSame('CALF_UNTAGGED', collect($response->json('data.warnings'))->pluck('code')->first());

        $line = collect($response->json('order.animals'))->firstWhere('caravan_id', $a->id);
        $this->assertNull($line['calf_identification']);
        $this->assertSame('Cría de BO-U1 ♀', $line['calf_label']);

        $calf = Caravan::find($line['calf_caravan_id']);
        $this->assertNull($calf->identification);
        $this->assertNull($calf->tagged_at);
        $this->assertSame($this->breedingA->id, (int) $calf->batch_id);
    }

    public function test_a_female_held_by_an_open_order_is_registered_in_that_order(): void
    {
        $held = $this->pregnantFemale('BO-H1');
        $other = $this->pregnantFemale('BO-H2');
        $free = $this->pregnantFemale('BO-H3');
        $openId = (int) $this->emit([$held, $other])->json('id');
        $before = BirthOrder::count();

        $response = $this->apiAs('POST', '/birth-orders/register', [
            'animals' => [
                ['caravan_id' => $free->id, 'outcome' => 'STILLBORN', 'birth_date' => now()->toDateString()],
                ['caravan_id' => $held->id, 'outcome' => 'LIVE', 'calf_identification' => 'BO-HC1', 'calf_sex' => 'H', 'birth_date' => now()->toDateString()],
            ],
        ])->assertStatus(201);

        // One new order, with only the free female; the held one advanced her own order.
        $this->assertSame($before + 1, BirthOrder::count());
        $this->assertCount(2, $response->json('orders'));
        $this->assertTrue($response->json('orders.0.created'));
        $this->assertSame([$free->id], collect($response->json('order.animals'))->pluck('caravan_id')->all());
        $this->assertSame($openId, $response->json('orders.1.id'));
        $this->assertFalse($response->json('orders.1.created'));
        $this->assertSame('PARTIAL', $response->json('orders.1.status'));
        $this->assertSame($this->breedingA->id, (int) Caravan::where('identification', 'BO-HC1')->value('batch_id'));
    }

    public function test_only_held_females_create_no_order(): void
    {
        $held = $this->pregnantFemale('BO-J1');
        $openId = (int) $this->emit([$held])->json('id');
        $before = BirthOrder::count();

        $response = $this->apiAs('POST', '/birth-orders/register', [
            'animals' => [['caravan_id' => $held->id, 'outcome' => 'PERINATAL_DEATH', 'calf_sex' => 'M', 'birth_date' => now()->toDateString()]],
        ])->assertStatus(201);

        $this->assertSame($before, BirthOrder::count());
        $this->assertSame($openId, $response->json('order.id'));
        $this->assertSame('EXECUTED', $response->json('order.status'));
    }

    public function test_a_failure_in_one_order_rolls_back_the_others(): void
    {
        $held = $this->pregnantFemale('BO-K1');
        $free = $this->pregnantFemale('BO-K2');
        $openId = (int) $this->emit([$held])->json('id');
        $before = BirthOrder::count();

        // The free female registers fine; the held one's calf takes a tag already in use.
        $this->apiAs('POST', '/birth-orders/register', [
            'animals' => [
                ['caravan_id' => $free->id, 'outcome' => 'STILLBORN', 'birth_date' => now()->toDateString()],
                ['caravan_id' => $held->id, 'outcome' => 'LIVE', 'calf_identification' => 'BO-K2', 'calf_sex' => 'M', 'birth_date' => now()->toDateString()],
            ],
        ])->assertStatus(422)->assertJsonPath('row_errors.0.caravana_madre', 'BO-K1');

        $this->assertSame($before, BirthOrder::count());
        $this->assertSame('ISSUED', BirthOrder::find($openId)->status);
        $this->assertNotNull($this->currentGestation($free->fresh()));
    }

    public function test_an_order_is_executed_from_the_screen(): void
    {
        $a = $this->pregnantFemale('BO-O1');
        $b = $this->pregnantFemale('BO-O2');
        $orderId = (int) $this->emit([$a, $b])->json('id');

        $response = $this->apiAs('POST', "/birth-orders/{$orderId}/execute", [
            'animals' => [
                ['caravan_id' => $a->id, 'outcome' => 'LIVE', 'calf_identification' => 'BO-OC1', 'calf_sex' => 'M', 'calf_teeth' => 0, 'birth_date' => now()->toDateString()],
                ['caravan_id' => $b->id],
            ],
        ])->assertStatus(201);

        $this->assertSame('PARTIAL', $response->json('data.birth_order.status'));
        $this->assertSame(1, $response->json('data.birth_order.pending_head_count'));
    }
}
