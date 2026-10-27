<?php

declare(strict_types=1);

namespace Tests\Feature\ServiceOrders;

use App\Models\Activity;
use App\Models\Batch;
use App\Models\Caravan;
use Tests\Feature\Veterinary\VeterinaryTestCase;

/**
 * Bull history of an order (assistant / MCP): how each bull joined and why he left.
 *
 * ANTHONY-TORO-TEST-004..006 are seeded APT.
 */
class ServiceOrderBullHistoryTest extends VeterinaryTestCase
{
    private Batch $rodeo;
    private Batch $bullPaddock;

    protected function setUp(): void
    {
        parent::setUp();

        $cria = Activity::updateOrCreate(['code' => 'CRIA'], ['name' => 'Cría']);
        $this->rodeo = $this->batch('Rodeo Historial', $cria->id);
        $this->bullPaddock = $this->batch('Torada Historial', $cria->id);
    }

    public function test_it_tells_how_each_bull_joined_and_why_he_left(): void
    {
        $this->cow('HIS-V-01');
        $first = $this->bullIn('ANTHONY-TORO-TEST-004');
        $second = $this->bullIn('ANTHONY-TORO-TEST-005');
        $spare = $this->bullIn('ANTHONY-TORO-TEST-006');
        $orderId = (int) $this->start([$first->id, $second->id])->assertStatus(201)->json('id');

        $this->apiAs('POST', "/service-orders/{$orderId}/replace-bull", [
            'retired_male_caravan_id' => $first->id,
            'replacement_male_caravan_id' => $spare->id,
            'replacement_date' => '2026-11-20',
            'reason' => 'LAMENESS_FOOT',
            'destination_batch_id' => $this->bullPaddock->id,
            'notes' => 'Renguea de la mano derecha',
        ])->assertStatus(201);

        $this->apiAs('POST', "/service-orders/{$orderId}/retire-bull", [
            'male_caravan_id' => $second->id,
            'retirement_date' => '2026-11-25',
            'reason' => 'SANITARY_REJECTION',
            'destination_batch_id' => $this->bullPaddock->id,
            'notes' => 'Positivo a Tricomonas',
        ])->assertStatus(200);

        $this->assertDatabaseHas('service_order_males', [
            'service_order_id' => $orderId, 'male_caravan_id' => $second->id,
            'exit_reason' => 'SANITARY_REJECTION', 'exit_notes' => 'Positivo a Tricomonas',
            'exit_destination_batch_id' => $this->bullPaddock->id,
        ]);

        $bulls = collect($this->apiAs('GET', "/service-orders/{$orderId}/bull-history")
            ->assertStatus(200)
            ->json('bulls'))->keyBy('identification');

        $this->assertSame('INITIAL', $bulls['ANTHONY-TORO-TEST-004']['entry']['kind']);
        $this->assertSame('REPLACED', $bulls['ANTHONY-TORO-TEST-004']['exit']['kind']);
        $this->assertSame('ANTHONY-TORO-TEST-006', $bulls['ANTHONY-TORO-TEST-004']['exit']['replaced_by']);
        $this->assertSame('Renguea de la mano derecha', $bulls['ANTHONY-TORO-TEST-004']['exit']['notes']);
        $this->assertSame('Torada Historial', $bulls['ANTHONY-TORO-TEST-004']['exit']['destination_batch']);

        $this->assertSame('RETIRED', $bulls['ANTHONY-TORO-TEST-005']['exit']['kind']);
        $this->assertSame('SANITARY_REJECTION', $bulls['ANTHONY-TORO-TEST-005']['exit']['reason']);
        $this->assertStringContainsString('Retirado sin reemplazo', $bulls['ANTHONY-TORO-TEST-005']['exit']['description']);

        $this->assertSame('REPLACEMENT', $bulls['ANTHONY-TORO-TEST-006']['entry']['kind']);
        $this->assertSame('2026-11-20', $bulls['ANTHONY-TORO-TEST-006']['entry']['date']);
        $this->assertSame('APT', $bulls['ANTHONY-TORO-TEST-006']['entry']['sanitary_status']);
        $this->assertNull($bulls['ANTHONY-TORO-TEST-006']['exit']);
    }

    public function test_an_order_of_another_company_is_not_found(): void
    {
        $this->apiAs('GET', '/service-orders/999999/bull-history')->assertStatus(404);
    }

    /**
     * @param int[] $bullIds
     * @return \Illuminate\Testing\TestResponse
     */
    private function start(array $bullIds)
    {
        return $this->apiAs('POST', "/batches/{$this->rodeo->id}/start-service", [
            'male_caravan_ids' => $bullIds,
            'planned_start_date' => '2026-11-01',
            'service_type' => count($bullIds) > 1 ? 'multi' : 'single',
            'is_controlled_service' => false,
        ]);
    }

    private function batch(string $name, int $activityId): Batch
    {
        return Batch::create([
            'company_id' => $this->company->id,
            'name' => $name,
            'is_active' => true,
            'activity_id' => $activityId,
        ]);
    }

    private function cow(string $tag): Caravan
    {
        return Caravan::create([
            'company_id' => $this->company->id,
            'batch_id' => $this->rodeo->id,
            'identification' => $tag,
            'sex' => 'H',
        ]);
    }

    private function bullIn(string $tag): Caravan
    {
        $bull = Caravan::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('identification', $tag)
            ->firstOrFail();
        $bull->update(['batch_id' => $this->bullPaddock->id]);

        return $bull;
    }
}
