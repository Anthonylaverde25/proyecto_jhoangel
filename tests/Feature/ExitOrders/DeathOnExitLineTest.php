<?php

declare(strict_types=1);

namespace Tests\Feature\ExitOrders;

use App\Models\Batch;
use App\Models\CaravanDeath;
use App\Models\DeathCause;

/**
 * A pending animal dies (01 §5.1): its line leaves the roll with the reason, the death goes to the
 * death batch as always, and the last one pending closes the order on its own.
 */
class DeathOnExitLineTest extends ExitOrderTestCase
{
    public function test_a_pending_animal_dies_from_its_line_and_the_last_one_closes_the_order(): void
    {
        [$first, $second] = [$this->steer('EX-D1'), $this->steer('EX-D2')];
        $id = $this->issue([$first, $second])->json('id');
        $cause = DeathCause::where('company_id', $this->company->id)->firstOrFail();

        $this->die($id, $first->id, $cause->id)
            ->assertOk()
            ->assertJsonPath('status', 'ISSUED')
            ->assertJsonPath('skipped_count', 1);

        $this->assertTrue(CaravanDeath::where('caravan_id', $first->id)->exists());
        $this->assertSame('Lote de Muertes', Batch::find($first->fresh()->batch_id)?->name);

        $this->die($id, $second->id, $cause->id)
            ->assertOk()
            ->assertJsonPath('status', 'CLOSED_INCOMPLETE')
            ->assertJsonPath('closing_reason', 'Baja por muerte: EX-D2');
    }

    public function test_a_death_that_fails_puts_the_line_back(): void
    {
        $steer = $this->steer('EX-D3');
        $id = $this->issue([$steer])->json('id');
        $cause = DeathCause::where('company_id', $this->company->id)->firstOrFail();

        $this->die($id, $steer->id, $cause->id, now()->addDay()->toDateString())->assertStatus(422);

        $this->apiAs('GET', "/exit-orders/{$id}")->assertJsonPath('pending_count', 1)->assertJsonPath('status', 'ISSUED');
        $this->assertFalse(CaravanDeath::where('caravan_id', $steer->id)->exists());
    }

    public function test_a_line_that_already_left_does_not_die_from_the_order(): void
    {
        $steer = $this->steer('EX-D4');
        $id = $this->registerExit([$this->line($steer)])->json('id');
        $cause = DeathCause::where('company_id', $this->company->id)->firstOrFail();

        $this->die($id, $steer->id, $cause->id)->assertStatus(422);
    }

    private function die(int $orderId, int $caravanId, int $causeId, ?string $date = null)
    {
        return $this->apiAs('POST', "/exit-orders/{$orderId}/animals/{$caravanId}/death", [
            'death_date' => $date ?? now()->toDateString(),
            'death_cause_id' => $causeId,
        ]);
    }
}
