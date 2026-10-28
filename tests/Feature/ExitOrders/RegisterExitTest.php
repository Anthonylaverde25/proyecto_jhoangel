<?php

declare(strict_types=1);

namespace Tests\Feature\ExitOrders;

use App\Models\Caravan;
use App\Models\CaravanMovement;
use App\Models\CaravanWeight;

/**
 * "Registrar egreso" and "Vender": an exit that already happened, born executed, with the snapshot
 * of where each animal was, its weighing and its SALE movement into the exit batch.
 */
class RegisterExitTest extends ExitOrderTestCase
{
    public function test_a_quick_sale_is_born_executed_with_the_snapshot_of_the_animal(): void
    {
        $steer = $this->steer('EX-R1');

        $response = $this->registerExit([$this->line($steer, ['weight_kg' => 412.5, 'price_per_kg' => 2100])])
            ->assertStatus(201)
            ->assertJsonPath('kind', 'REGISTERED')
            ->assertJsonPath('status', 'EXECUTED')
            ->assertJsonPath('exited_count', 1)
            ->assertJsonPath('without_dte', true)
            ->assertJsonPath('buyer.name', 'Frigorífico de Prueba')
            ->assertJsonPath('animals.0.label', 'EX-R1')
            ->assertJsonPath('animals.0.from_batch.id', $this->breedingA->id)
            ->assertJsonPath('animals.0.activity.id', $this->activityId('CRIA'))
            ->assertJsonPath('animals.0.amount', 866250);

        $this->assertMatchesRegularExpression('/^EG-\d{8}-0001$/', $response->json('code'));
        $this->assertSame('INTERNAL_EXIT', $this->exitBatchOf($steer));
        $this->assertTrue(CaravanMovement::where('caravan_id', $steer->id)->where('type', 'SALE')->exists());

        $weight = CaravanWeight::where('caravan_id', $steer->id)->latest('id')->firstOrFail();
        $this->assertSame('EXIT_ORDER', $weight->source);
        $this->assertEqualsWithDelta(412.5, (float) $steer->fresh()->exit_weight, 0.001);

        $this->apiAs('GET', "/caravans/{$steer->id}")->assertJsonPath('data.exit.order_code', $response->json('code'));
    }

    public function test_without_price_per_kg_a_per_head_amount_is_kept(): void
    {
        $steer = $this->steer('EX-R2');

        $this->registerExit([$this->line($steer, ['amount' => 950000])])
            ->assertStatus(201)
            ->assertJsonPath('animals.0.amount', 950000)
            ->assertJsonPath('animals.0.weight_kg', null)
            ->assertJsonPath('total_amount', 950000);
    }

    public function test_the_dte_comes_along_and_a_number_covers_one_exit_only(): void
    {
        $first = $this->steer('EX-R3');
        $second = $this->steer('EX-R4');
        $dte = ['dte_number' => 'dte-eg-01', 'dte_date' => now()->toDateString(), 'head_count' => 1];

        $this->registerExit([$this->line($first)], ['dte' => $dte])
            ->assertStatus(201)
            ->assertJsonPath('without_dte', false)
            ->assertJsonPath('dtes.0.dte_number', 'DTE-EG-01');

        $this->registerExit([$this->line($second)], ['dte' => $dte])
            ->assertStatus(422)
            ->assertJsonValidationErrors('dte_number');

        // The whole registration rolled back: the second steer did not leave.
        $this->assertSame($this->breedingA->id, (int) $second->fresh()->batch_id);
    }

    public function test_the_exit_batch_is_created_once_and_reused(): void
    {
        $this->registerExit([$this->line($this->steer('EX-R5'))])->assertStatus(201);
        $this->registerExit([$this->line($this->steer('EX-R6'))])->assertStatus(201);

        $this->assertSame(1, Caravan::whereIn('identification', ['EX-R5', 'EX-R6'])->distinct()->count('batch_id'));
        $this->assertCount(1, $this->apiAs('GET', '/exit-batches')->json());
        $this->assertSame(2, count($this->apiAs('GET', '/exit-orders?status=EXECUTED')->json()));
    }
}
