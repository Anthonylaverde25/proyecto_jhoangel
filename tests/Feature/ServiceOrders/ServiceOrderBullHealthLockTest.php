<?php

declare(strict_types=1);

namespace Tests\Feature\ServiceOrders;

use App\Models\Activity;
use App\Models\Batch;
use App\Models\BullHealthEvaluation;
use App\Models\Caravan;
use App\Models\ServiceOrderMale;
use Tests\Feature\Veterinary\VeterinaryTestCase;

/**
 * F5 / ADR-5, revised: the veterinary module is optional. The owner's decision and the
 * veterinary verdict are two independent dimensions; only the verdicts that exist count.
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

    public function test_an_apt_bull_is_accepted_and_the_order_keeps_its_status(): void
    {
        $bull = $this->bull('LOCK-APT', 'APT');

        $response = $this->createOrder($bull, 'SO-LOCK-APT')->assertStatus(201);

        $this->assertSame('APT', $this->statusAtEntry((int) $response->json('id'), $bull));
    }

    public function test_an_unfit_bull_is_rejected_as_not_apt(): void
    {
        $bull = $this->bull('LOCK-UNFIT', 'UNFIT');

        $response = $this->createOrder($bull, 'SO-LOCK-UNFIT');

        $response->assertStatus(422);
        $this->assertStringContainsString('no está apto', (string) $response->json('message'));
    }

    public function test_a_bull_under_treatment_is_rejected(): void
    {
        $bull = $this->bull('LOCK-TREAT', 'IN_TREATMENT');

        $this->createOrder($bull, 'SO-LOCK-TREAT')->assertStatus(422);
    }

    public function test_a_bull_with_no_sanitary_evaluation_enters_on_the_owners_decision(): void
    {
        $bull = $this->bull('LOCK-SIN-EVAL', null);

        $response = $this->createOrder($bull, 'SO-LOCK-SIN-EVAL')->assertStatus(201);

        // Kept as "never evaluated", so a later adoption of the module can tell it apart.
        $this->assertNull($this->statusAtEntry((int) $response->json('id'), $bull));
    }

    public function test_a_never_evaluated_bull_with_a_positive_diagnosis_is_rejected(): void
    {
        $bull = $this->bull('LOCK-SIN-EVAL-POS', null);
        $pathogen = \App\Models\Pathogen::where('code', 'TRITRICHOMONAS_FOETUS')->firstOrFail();

        // The diagnosis alone gives him a verdict: he is no longer "not evaluated".
        $this->apiAs('POST', "/caravans/{$bull->id}/diagnoses", [
            'pathogen_id' => $pathogen->id,
            'status' => 'CONFIRMED_POSITIVE',
        ])->assertStatus(201);

        $response = $this->createOrder($bull, 'SO-LOCK-SIN-EVAL-POS');

        $response->assertStatus(422);
        $this->assertStringContainsString('no está apto', (string) $response->json('message'));
    }

    public function test_a_bull_with_a_pending_evaluation_enters(): void
    {
        $bull = $this->bull('LOCK-PEND', 'PENDING_EVALUATION');

        $response = $this->createOrder($bull, 'SO-LOCK-PEND')->assertStatus(201);

        $this->assertSame('PENDING_EVALUATION', $this->statusAtEntry((int) $response->json('id'), $bull));
    }

    public function test_a_bull_the_owner_excluded_is_rejected_even_when_apt(): void
    {
        $bull = $this->bull('LOCK-RESTING', 'APT');
        $bull->update(['is_breeding_eligible' => false, 'breeding_exclusion_reason' => 'RESTING']);

        $response = $this->createOrder($bull, 'SO-LOCK-RESTING');

        $response->assertStatus(422);
        $message = (string) $response->json('message');
        $this->assertStringContainsString('excluido de reproducción', $message);
        $this->assertStringContainsString('Descanso', $message);
    }

    public function test_certified_deployments_still_block_a_bull_never_evaluated(): void
    {
        config(['livestock.service_order.require_sanitary_certification' => true]);
        $bull = $this->bull('LOCK-CERT', null);

        $response = $this->createOrder($bull, 'SO-LOCK-CERT');

        $response->assertStatus(422);
        $message = mb_strtolower((string) $response->json('message'));
        $this->assertStringContainsString('no tiene evaluación sanitaria registrada', $message);
        $this->assertStringNotContainsString('no está apto', $message);
    }

    public function test_certified_deployments_block_a_pending_evaluation(): void
    {
        config(['livestock.service_order.require_sanitary_certification' => true]);
        $bull = $this->bull('LOCK-CERT-PEND', 'PENDING_EVALUATION');

        $response = $this->createOrder($bull, 'SO-LOCK-CERT-PEND');

        $response->assertStatus(422);
        $this->assertStringContainsString('pendiente', (string) $response->json('message'));
    }

    private function statusAtEntry(int $orderId, Caravan $bull): ?string
    {
        return ServiceOrderMale::withoutGlobalScopes()
            ->where('service_order_id', $orderId)
            ->where('male_caravan_id', $bull->id)
            ->value('sanitary_status_at_entry');
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
