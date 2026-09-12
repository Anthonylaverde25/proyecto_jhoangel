<?php

declare(strict_types=1);

namespace Tests\Feature\ServiceOrders;

use App\Models\Activity;
use App\Models\Batch;
use App\Models\BullHealthEvaluation;
use App\Models\Caravan;
use Tests\Feature\Veterinary\VeterinaryTestCase;

/**
 * F5 / ADR-5. The lock used to be fail-open: a bull that had never been evaluated walked
 * straight into the service order. The three scenarios must be told apart, and the message
 * for a missing protocol must not read like a sick animal.
 */
class ServiceOrderBullHealthLockTest extends VeterinaryTestCase
{
    private Batch $breedingBatch;

    protected function setUp(): void
    {
        parent::setUp();

        $cria = Activity::updateOrCreate(['code' => 'CRIA'], ['name' => 'Cría']);

        $this->breedingBatch = Batch::create([
            'company_id' => $this->company->id,
            'name' => 'Lote Cría Candado',
            'is_active' => true,
            'activity_id' => $cria->id,
        ]);
    }

    public function test_an_apt_bull_is_accepted(): void
    {
        $bull = $this->bull('LOCK-APT', 'APT');

        $this->createOrder($bull, 'SO-LOCK-APT')->assertStatus(201);
    }

    public function test_an_unfit_bull_is_rejected_as_not_apt(): void
    {
        $bull = $this->bull('LOCK-UNFIT', 'UNFIT');

        $response = $this->createOrder($bull, 'SO-LOCK-UNFIT');

        $response->assertStatus(422);
        $this->assertStringContainsString('no está apto', (string) $response->json('message'));
    }

    public function test_a_bull_with_no_sanitary_evaluation_is_blocked_with_its_own_message(): void
    {
        $bull = $this->bull('LOCK-SIN-EVAL', null);

        $response = $this->createOrder($bull, 'SO-LOCK-SIN-EVAL');

        $response->assertStatus(422);

        $message = (string) $response->json('message');

        // The distinction matters operationally: load the protocol, do not cull the animal.
        $this->assertStringContainsString('no tiene evaluación sanitaria registrada', mb_strtolower($message));
        $this->assertStringContainsString('cargue el protocolo diagnóstico', mb_strtolower($message));
        $this->assertStringNotContainsString('no está apto', $message);
    }

    private function bull(string $identification, ?string $aptitudeStatus): Caravan
    {
        $bull = Caravan::create([
            'company_id' => $this->company->id,
            'identification' => $identification,
            'sex' => 'M',
            'batch_id' => $this->breedingBatch->id,
        ]);

        if ($aptitudeStatus !== null) {
            BullHealthEvaluation::create([
                'company_id' => $this->company->id,
                'caravan_id' => $bull->id,
                'last_evaluation_date' => now()->subDays(5)->toDateString(),
                'aplomo_notes' => 'Aplomos correctos.',
                'scrotal_circumference_cm' => 36.0,
                'body_condition_score' => 3.5,
                'libido' => 'ALTA',
                'status' => $aptitudeStatus,
            ]);
        }

        return $bull;
    }

    /**
     * @return \Illuminate\Testing\TestResponse
     */
    private function createOrder(Caravan $bull, string $code)
    {
        $cow = Caravan::create([
            'company_id' => $this->company->id,
            'identification' => $code . '-COW',
            'sex' => 'H',
            'batch_id' => $this->breedingBatch->id,
        ]);

        return $this->apiAs('POST', '/service-orders', [
            'batch_id' => $this->breedingBatch->id,
            'code' => $code,
            'planned_start_date' => now()->addDays(10)->toDateString(),
            'male_caravan_ids' => [$bull->id],
            'female_caravan_ids' => [$cow->id],
        ]);
    }
}
