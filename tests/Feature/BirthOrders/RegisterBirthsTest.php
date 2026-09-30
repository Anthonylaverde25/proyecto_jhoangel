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
                ['caravan_id' => $b->id, 'outcome' => 'ABORTION', 'birth_date' => now()->toDateString()],
            ],
        ])->assertStatus(201);

        $this->assertSame('EXECUTED', $response->json('order.status'));
        $this->assertSame('REGISTERED', $response->json('order.kind'));
        $this->assertSame(1, $response->json('data.live_count'));
        $this->assertSame(1, $response->json('data.abortion_count'));
        $this->assertSame($this->breedingA->id, (int) Caravan::where('identification', 'BO-MC1')->value('batch_id'));
    }

    public function test_a_registration_leaves_nothing_pending_and_a_failure_leaves_no_order(): void
    {
        $a = $this->pregnantFemale('BO-N1');
        $before = BirthOrder::count();

        $this->apiAs('POST', '/birth-orders/register', [
            'animals' => [['caravan_id' => $a->id]],
        ])->assertStatus(422);

        // Missing calf tag: the processing refuses it, and the order is rolled back.
        $this->apiAs('POST', '/birth-orders/register', [
            'animals' => [['caravan_id' => $a->id, 'outcome' => 'LIVE', 'calf_sex' => 'M', 'birth_date' => now()->toDateString()]],
        ])->assertStatus(422)->assertJsonPath('row_errors.0.errors.0.code', 'CALF_TAG_MISSING');

        $this->assertSame($before, BirthOrder::count());
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
