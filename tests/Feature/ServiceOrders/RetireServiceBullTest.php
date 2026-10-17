<?php

declare(strict_types=1);

namespace Tests\Feature\ServiceOrders;

use App\Models\Activity;
use App\Models\Batch;
use App\Models\Caravan;
use App\Models\ServiceOrderMale;
use Tests\Feature\Veterinary\VeterinaryTestCase;

/**
 * A bull leaves a running service: replaced by another one, or retired without replacement
 * when there is no spare bull (e.g. positive to a venereal disease).
 *
 * ANTHONY-TORO-TEST-004..006 are seeded APT.
 */
class RetireServiceBullTest extends VeterinaryTestCase
{
    private Batch $rodeo;
    private Batch $bullPaddock;

    protected function setUp(): void
    {
        parent::setUp();

        $cria = Activity::updateOrCreate(['code' => 'CRIA'], ['name' => 'Cría']);
        $this->rodeo = $this->batch('Rodeo Retiro', $cria->id);
        $this->bullPaddock = $this->batch('Torada Retiro', $cria->id);
    }

    public function test_a_replacement_retires_one_bull_and_brings_in_the_other(): void
    {
        $this->cow('RET-V-01');
        $out = $this->bullIn('ANTHONY-TORO-TEST-004');
        $in = $this->bullIn('ANTHONY-TORO-TEST-005');
        $orderId = (int) $this->start([$out->id])->assertStatus(201)->json('id');

        $this->apiAs('POST', "/service-orders/{$orderId}/replace-bull", [
            'retired_male_caravan_id' => $out->id,
            'replacement_male_caravan_id' => $in->id,
            'replacement_date' => '2026-11-20',
            'reason' => 'LAMENESS_FOOT',
            'destination_batch_id' => $this->bullPaddock->id,
        ])->assertStatus(201);

        $this->assertDatabaseHas('service_order_males', [
            'service_order_id' => $orderId, 'male_caravan_id' => $out->id, 'status' => 'RETIRED_INJURED',
        ]);
        $this->assertDatabaseHas('service_order_males', [
            'service_order_id' => $orderId, 'male_caravan_id' => $in->id, 'status' => 'ACTIVE',
            'joined_at' => '2026-11-20', 'sanitary_status_at_entry' => 'APT',
        ]);
        $this->assertSame($this->bullPaddock->id, $out->fresh()->batch_id);
        $this->assertSame($this->rodeo->id, $in->fresh()->batch_id);
    }

    public function test_a_bull_is_retired_without_replacement(): void
    {
        $this->cow('RET-V-02');
        $positive = $this->bullIn('ANTHONY-TORO-TEST-004');
        $other = $this->bullIn('ANTHONY-TORO-TEST-005');
        $orderId = (int) $this->start([$positive->id, $other->id])->assertStatus(201)->json('id');

        $response = $this->apiAs('POST', "/service-orders/{$orderId}/retire-bull", [
            'male_caravan_id' => $positive->id,
            'retirement_date' => '2026-11-15',
            'reason' => 'SANITARY_REJECTION',
            'destination_batch_id' => $this->bullPaddock->id,
            'notes' => 'Positivo a Tricomonas',
        ]);

        $response->assertStatus(200);
        $this->assertSame(1, $response->json('remaining_active_bulls'));
        $this->assertNull($response->json('warning'));

        $row = ServiceOrderMale::withoutGlobalScopes()
            ->where('service_order_id', $orderId)->where('male_caravan_id', $positive->id)->firstOrFail();
        $this->assertSame('RETIRED_SANITARY', $row->status);
        $this->assertSame('2026-11-15', $row->retired_at->format('Y-m-d'));
        $this->assertSame($this->bullPaddock->id, $positive->fresh()->batch_id);
        $this->assertDatabaseHas('caravan_movements', [
            'caravan_id' => $positive->id, 'from_batch_id' => $this->rodeo->id, 'to_batch_id' => $this->bullPaddock->id,
        ]);
    }

    public function test_retiring_the_last_bull_warns_that_the_order_has_none(): void
    {
        $this->cow('RET-V-03');
        $bull = $this->bullIn('ANTHONY-TORO-TEST-006');
        $orderId = (int) $this->start([$bull->id])->assertStatus(201)->json('id');

        $response = $this->apiAs('POST', "/service-orders/{$orderId}/retire-bull", [
            'male_caravan_id' => $bull->id,
            'retirement_date' => '2026-11-15',
            'reason' => 'SANITARY_REJECTION',
        ]);

        $response->assertStatus(200);
        $this->assertSame(0, $response->json('remaining_active_bulls'));
        $this->assertStringContainsString('sin toros', (string) $response->json('warning'));
        // Without a destination the bull goes back where he came from.
        $this->assertSame($this->bullPaddock->id, $bull->fresh()->batch_id);
    }

    public function test_a_bull_no_longer_active_cannot_be_retired(): void
    {
        $this->cow('RET-V-04');
        $bull = $this->bullIn('ANTHONY-TORO-TEST-004');
        $orderId = (int) $this->start([$bull->id])->assertStatus(201)->json('id');
        $payload = ['male_caravan_id' => $bull->id, 'retirement_date' => '2026-11-15', 'reason' => 'OTHER'];

        $this->apiAs('POST', "/service-orders/{$orderId}/retire-bull", $payload)->assertStatus(200);
        $this->apiAs('POST', "/service-orders/{$orderId}/retire-bull", $payload)->assertStatus(422);
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
