<?php

declare(strict_types=1);

namespace Tests\Feature\WorkTemplates;

use App\Models\AnimalCategory;
use App\Models\Batch;
use App\Models\Caravan;
use App\Models\CaravanGestation;
use App\Models\CaravanMovement;
use App\Models\ServiceOrder;
use App\Models\WorkTemplate;
use Tests\Feature\Veterinary\VeterinaryTestCase;

/**
 * LSER-01: a hand-filled single-bull sheet creates the service batch, its order and the
 * movements. All or nothing: every problem comes back at once and nothing is persisted.
 *
 * ANTHONY-TORO-TEST-004..006 are seeded APT; ANTHONY-TORO-TEST-001 has no evaluation.
 */
class Lser01TemplateProcessingTest extends VeterinaryTestCase
{
    private Batch $originBatch;
    private AnimalCategory $vaquillona;
    private AnimalCategory $vaca;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originBatch = Batch::create([
            'company_id' => $this->company->id,
            'name' => 'Recría Origen LSER',
            'is_active' => true,
        ]);

        $this->vaquillona = AnimalCategory::firstOrCreate(['code' => 'VAQUILLONA'], ['name' => 'Vaquillona', 'sex' => 'H']);
        $this->vaca = AnimalCategory::firstOrCreate(['code' => 'VACA'], ['name' => 'Vaca', 'sex' => 'H']);
    }

    public function test_a_clean_sheet_creates_batch_order_and_movements(): void
    {
        $cows = [$this->cow('LSER-V-01'), $this->cow('LSER-V-02'), $this->cow('LSER-V-03')];

        $response = $this->submit('ANTHONY-TORO-TEST-004', ['LSER-V-01', 'LSER-V-02', 'LSER-V-03'], 'Entore LSER 004');

        $response->assertStatus(201);

        $batch = Batch::withoutGlobalScopes()->findOrFail($response->json('data.batch_id'));
        $this->assertSame('Entore LSER 004', $batch->name);

        $order = ServiceOrder::withoutGlobalScopes()->findOrFail($response->json('data.service_order_id'));
        $this->assertSame('APPROVED', $order->status);
        $this->assertSame('single', $order->service_type);
        $this->assertSame($batch->id, $order->batch_id);
        $this->assertCount(1, $order->males);
        $this->assertCount(3, $order->females);
        $this->assertSame($order->code, $response->json('data.service_order_code'));

        foreach ($cows as $cow) {
            $this->assertSame($batch->id, $cow->fresh()->batch_id);
            $this->assertDatabaseHas('caravan_movements', [
                'caravan_id' => $cow->id,
                'from_batch_id' => $this->originBatch->id,
                'to_batch_id' => $batch->id,
                'type' => 'TRANSFER',
            ]);
        }

        $this->assertSame(4, CaravanMovement::where('to_batch_id', $batch->id)->count());
        $this->assertSame($this->vaquillona->id, $batch->serviceDetail->female_category_id);
    }

    public function test_a_bull_without_evaluation_blocks_the_whole_sheet(): void
    {
        $this->cow('LSER-V-10');

        $response = $this->submit('ANTHONY-TORO-TEST-001', ['LSER-V-10'], 'Entore LSER 001');

        $response->assertStatus(422);
        $this->assertSame(['BULL_NOT_FIT'], array_column($response->json('header_errors'), 'code'));
        $this->assertStringContainsString('no tiene evaluación sanitaria registrada', $response->json('header_errors.0.message'));
        $this->assertNothingPersisted('Entore LSER 001');
    }

    public function test_every_row_problem_is_reported_in_a_single_response(): void
    {
        $this->cow('LSER-V-20');
        $pregnant = $this->cow('LSER-V-21');
        CaravanGestation::create(['caravan_id' => $pregnant->id, 'start_date' => now()->subMonths(2)->toDateString(), 'is_current' => true]);

        $response = $this->submit(
            'ANTHONY-TORO-TEST-005',
            ['LSER-V-20', 'NO-EXISTE-999', 'LSER-V-21', 'LSER-V-20', '', 'ANTHONY-TORO-TEST-006'],
            'Entore LSER errores'
        );

        $response->assertStatus(422);
        $this->assertSame([], $response->json('header_errors'));

        $codesByRow = collect($response->json('row_errors'))
            ->mapWithKeys(fn ($row) => [$row['row_index'] => array_column($row['errors'], 'code')])
            ->all();

        $this->assertSame([
            1 => ['NOT_FOUND'],
            2 => ['PREGNANT'],
            3 => ['DUPLICATED_IN_SHEET'],
            5 => ['NOT_FEMALE'],
        ], $codesByRow);

        $this->assertNothingPersisted('Entore LSER errores');
    }

    public function test_a_female_of_another_category_is_flagged(): void
    {
        $this->cow('LSER-V-30');
        $this->cow('LSER-V-31');
        $this->cow('LSER-V-32', $this->vaca);

        $response = $this->submit('ANTHONY-TORO-TEST-005', ['LSER-V-30', 'LSER-V-31', 'LSER-V-32'], 'Entore LSER categorías');

        $response->assertStatus(422);
        $this->assertSame(2, $response->json('row_errors.0.row_index'));
        $this->assertSame('CATEGORY_MISMATCH', $response->json('row_errors.0.errors.0.code'));
        $this->assertNothingPersisted('Entore LSER categorías');
    }

    public function test_a_female_already_in_an_active_order_is_flagged(): void
    {
        $this->cow('LSER-V-40');
        $this->cow('LSER-V-41');

        $this->submit('ANTHONY-TORO-TEST-005', ['LSER-V-40'], 'Entore LSER primero')->assertStatus(201);

        $response = $this->submit('ANTHONY-TORO-TEST-006', ['LSER-V-41', 'LSER-V-40'], 'Entore LSER segundo');

        $response->assertStatus(422);
        $this->assertSame(1, $response->json('row_errors.0.row_index'));
        $this->assertContains('IN_ACTIVE_ORDER', array_column($response->json('row_errors.0.errors'), 'code'));
        $this->assertNothingPersisted('Entore LSER segundo');
    }

    public function test_the_wizard_now_rejects_a_bull_without_evaluation(): void
    {
        $cow = $this->cow('LSER-V-50');
        $unevaluated = $this->caravan('ANTHONY-TORO-TEST-001');

        $response = $this->apiAs('POST', '/batches/service', [
            'name' => 'Wizard toro sin evaluar',
            'female_category_id' => $this->vaquillona->id,
            'male_category_id' => $unevaluated->category_id,
            'female_caravan_ids' => [$cow->id],
            'male_caravan_ids' => [$unevaluated->id],
            'planned_start_date' => now()->addDays(5)->toDateString(),
            'auto_create_service_order' => true,
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('no tiene evaluación sanitaria registrada', (string) $response->json('message'));
        $this->assertNothingPersisted('Wizard toro sin evaluar');
    }

    public function test_the_tenant_seed_registers_lser01(): void
    {
        $template = WorkTemplate::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('code', 'LSER-01')
            ->firstOrFail();

        $this->assertSame('REPRODUCTIVE', $template->category);
        $this->assertSame(
            ['lote', 'toro_caravana', 'planned_start_date', 'planned_end_date', 'responsable', 'observaciones'],
            array_column($template->schema_definition['header_fields'], 'name')
        );
        $this->assertSame(['caravana', 'observations'], array_column($template->schema_definition['table_columns'], 'name'));
    }

    /**
     * @param list<string> $femaleTags
     * @return \Illuminate\Testing\TestResponse
     */
    private function submit(string $bullTag, array $femaleTags, string $batchName)
    {
        return $this->apiAs('POST', '/work-templates/lser-01/process', [
            'lote' => $batchName,
            'toro_caravana' => $bullTag,
            'planned_start_date' => now()->addDays(5)->toDateString(),
            'responsable' => 'Operador de manga',
            'rows' => array_map(fn (string $tag) => ['caravana' => $tag, 'observations' => null], $femaleTags),
        ]);
    }

    private function cow(string $tag, ?AnimalCategory $category = null): Caravan
    {
        return Caravan::create([
            'company_id' => $this->company->id,
            'batch_id' => $this->originBatch->id,
            'identification' => $tag,
            'sex' => 'H',
            'teeth' => 4,
            'category_id' => ($category ?? $this->vaquillona)->id,
        ]);
    }

    private function caravan(string $tag): Caravan
    {
        return Caravan::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('identification', $tag)
            ->firstOrFail();
    }

    private function assertNothingPersisted(string $batchName): void
    {
        $this->assertFalse(Batch::withoutGlobalScopes()->where('name', $batchName)->exists(), "Se creó el lote '{$batchName}'.");
        $this->assertSame(0, CaravanMovement::where('observations', 'like', "%{$batchName}%")->count());
    }
}
