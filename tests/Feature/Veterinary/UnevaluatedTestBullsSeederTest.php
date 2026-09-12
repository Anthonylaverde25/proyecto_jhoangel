<?php

declare(strict_types=1);

namespace Tests\Feature\Veterinary;

use App\Models\Batch;
use App\Models\BullHealthEvaluation;
use App\Models\BullLabSample;
use App\Models\Caravan;
use App\Models\VeterinarianBatchAssignment;
use App\Models\VeterinaryDiagnosis;
use Database\Seeders\BullAptitudeRecalculationSeeder;
use Database\Seeders\UnevaluatedTestBullsSeeder;

/**
 * The three ANTHONY-TORO-TEST bulls exist to exercise the sanitary rules from zero, so their
 * emptiness is the fixture. It is also fragile: BullAptitudeRecalculationSeeder creates an
 * evaluation row for any bull lacking one, and reordering the seeder chain would silently
 * give these three a history and quietly weaken every test written against them.
 */
class UnevaluatedTestBullsSeederTest extends VeterinaryTestCase
{
    private const TAGS = [
        'ANTHONY-TORO-TEST-001',
        'ANTHONY-TORO-TEST-002',
        'ANTHONY-TORO-TEST-003',
    ];

    public function test_the_three_test_bulls_start_with_no_sanitary_history(): void
    {
        foreach (self::TAGS as $tag) {
            $caravan = $this->testBull($tag);

            $this->assertSame(0, BullHealthEvaluation::withoutGlobalScopes()->where('caravan_id', $caravan->id)->count(), $tag);
            $this->assertSame(0, BullLabSample::withoutGlobalScopes()->where('caravan_id', $caravan->id)->count(), $tag);
            $this->assertSame(0, VeterinaryDiagnosis::withoutGlobalScopes()->where('caravan_id', $caravan->id)->count(), $tag);
        }
    }

    public function test_they_live_in_an_own_breeding_batch_usable_as_a_service_order_target(): void
    {
        $batch = Batch::withoutGlobalScopes()->findOrFail($this->testBull(self::TAGS[0])->batch_id);

        // CreateServiceOrderUseCase rejects any target batch that belongs to a farm.
        $this->assertNull($batch->farm_id);
        $this->assertSame('CRIA', \App\Models\Activity::withoutGlobalScopes()->find($batch->activity_id)?->code);
    }

    public function test_the_batch_is_reachable_from_the_veterinary_portal(): void
    {
        $batchId = (int) $this->testBull(self::TAGS[0])->batch_id;

        $this->assertTrue(
            VeterinarianBatchAssignment::withoutGlobalScopes()
                ->where('batch_id', $batchId)
                ->whereNull('unassigned_at')
                ->exists(),
            'Sin asignación vigente el portal no muestra estos toros y la regla venérea no se puede probar allí.'
        );
    }

    /**
     * The guard that matters: running the recompute first and the seeder afterwards is what the
     * chain does, and the result must still be an empty history.
     */
    public function test_a_later_aptitude_recompute_does_not_leave_them_with_a_history(): void
    {
        $this->seed(BullAptitudeRecalculationSeeder::class);

        // The recompute alone does create a row: that is its documented behaviour.
        $this->assertGreaterThan(
            0,
            BullHealthEvaluation::withoutGlobalScopes()->where('caravan_id', $this->testBull(self::TAGS[0])->id)->count()
        );

        $this->seed(UnevaluatedTestBullsSeeder::class);

        foreach (self::TAGS as $tag) {
            $this->assertSame(
                0,
                BullHealthEvaluation::withoutGlobalScopes()->where('caravan_id', $this->testBull($tag)->id)->count(),
                $tag . ' debería volver a quedar sin evaluaciones tras la resiembra.'
            );
        }
    }

    public function test_the_service_order_lock_reports_them_as_never_evaluated(): void
    {
        $bull = $this->testBull(self::TAGS[0]);
        $batchId = (int) $bull->batch_id;

        $cow = Caravan::create([
            'company_id' => $this->company->id,
            'identification' => 'QA-VACA-CRUDO',
            'sex' => 'H',
            'batch_id' => $batchId,
        ]);

        $response = $this->apiAs('POST', '/service-orders', [
            'batch_id' => $batchId,
            'code' => 'QA-SO-CRUDO',
            'planned_start_date' => now()->addDays(10)->toDateString(),
            'male_caravan_ids' => [$bull->id],
            'female_caravan_ids' => [$cow->id],
        ]);

        $response->assertStatus(422);

        $message = mb_strtolower((string) $response->json('message'));

        // Distinto del rechazo por "no apto": acá falta cargar el protocolo, no descartar el animal.
        $this->assertStringContainsString('no tiene evaluación sanitaria registrada', $message);
        $this->assertStringNotContainsString('no está apto', $message);
    }

    private function testBull(string $tag): Caravan
    {
        return Caravan::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('identification', $tag)
            ->firstOrFail();
    }
}
