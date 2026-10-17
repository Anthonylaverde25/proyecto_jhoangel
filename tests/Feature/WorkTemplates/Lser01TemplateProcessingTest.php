<?php

declare(strict_types=1);

namespace Tests\Feature\WorkTemplates;

use App\Models\Activity;
use App\Models\AnimalCategory;
use App\Models\Batch;
use App\Models\Caravan;
use App\Models\CaravanGestation;
use App\Models\CaravanMovement;
use App\Models\ServiceOrder;
use App\Models\WorkTemplate;
use Tests\Feature\Veterinary\VeterinaryTestCase;

/**
 * LSER-01: service start record over an existing Cría batch. A clean sheet opens the service
 * order on the batch and brings the bull in; the females never move. All or nothing: every
 * blocking problem comes back at once and nothing is persisted.
 *
 * ANTHONY-TORO-TEST-004..006 are seeded APT; ANTHONY-TORO-TEST-001 has no evaluation, which no
 * longer blocks: the veterinary module is optional.
 */
class Lser01TemplateProcessingTest extends VeterinaryTestCase
{
    private Batch $rodeo;
    private Batch $otherBatch;
    private AnimalCategory $vaquillona;
    private AnimalCategory $ternero;

    protected function setUp(): void
    {
        parent::setUp();

        $cria = Activity::updateOrCreate(['code' => 'CRIA'], ['name' => 'Cría']);

        $this->rodeo = Batch::create([
            'company_id' => $this->company->id,
            'name' => 'Rodeo LSER',
            'is_active' => true,
            'activity_id' => $cria->id,
        ]);

        $this->otherBatch = Batch::create([
            'company_id' => $this->company->id,
            'name' => 'Otro Rodeo LSER',
            'is_active' => true,
            'activity_id' => $cria->id,
        ]);

        $this->vaquillona = AnimalCategory::updateOrCreate(['code' => 'VAQUILLONA'], ['name' => 'Vaquillona', 'sex' => 'H', 'is_reproductive' => true]);
        $this->ternero = AnimalCategory::updateOrCreate(['code' => 'TERNERO'], ['name' => 'Ternero', 'sex' => 'BOTH', 'is_reproductive' => false]);
    }

    public function test_a_clean_sheet_starts_the_service_on_the_batch(): void
    {
        $cows = [$this->cow('LSER-V-01'), $this->cow('LSER-V-02'), $this->cow('LSER-V-03')];
        $bull = $this->caravan('ANTHONY-TORO-TEST-004');

        $response = $this->submit('ANTHONY-TORO-TEST-004', ['LSER-V-01', 'LSER-V-02', 'LSER-V-03']);

        $response->assertStatus(201);
        $this->assertSame($this->rodeo->id, $response->json('data.batch_id'));
        $this->assertSame([], $response->json('data.warnings'));

        $order = ServiceOrder::withoutGlobalScopes()->findOrFail($response->json('data.service_order_id'));
        $this->assertSame('APPROVED', $order->status);
        $this->assertSame('single', $order->service_type);
        $this->assertSame($this->rodeo->id, $order->batch_id);
        $this->assertMatchesRegularExpression('/^SO-\d{4}-\d{3}$/', $order->code);
        $this->assertCount(1, $order->males);
        $this->assertCount(3, $order->females);

        // The females stay in their batch: no movement is written for them.
        foreach ($cows as $cow) {
            $this->assertSame($this->rodeo->id, $cow->fresh()->batch_id);
            $this->assertSame(0, CaravanMovement::where('caravan_id', $cow->id)->count());
        }
        foreach ($order->females as $female) {
            $this->assertNotNull($female->pivot->exposed_from);
        }

        // The bull joins them.
        $this->assertSame($this->rodeo->id, $bull->fresh()->batch_id);
        $this->assertDatabaseHas('caravan_movements', [
            'caravan_id' => $bull->id,
            'to_batch_id' => $this->rodeo->id,
            'type' => 'TRANSFER',
        ]);

        // No batch is created for the service.
        $this->assertSame(2, Batch::withoutGlobalScopes()->whereIn('name', ['Rodeo LSER', 'Otro Rodeo LSER'])->count());
    }

    public function test_a_female_of_the_batch_missing_from_the_sheet_is_a_warning_and_is_exposed_anyway(): void
    {
        $this->cow('LSER-V-05');
        $this->cow('LSER-V-06');
        $this->cow('LSER-V-07');

        $response = $this->submit('ANTHONY-TORO-TEST-005', ['LSER-V-05', 'LSER-V-06']);

        $response->assertStatus(201);
        $this->assertSame(['IN_BATCH_NOT_LISTED'], array_column($response->json('data.warnings'), 'code'));
        $this->assertSame(['LSER-V-07'], array_column($response->json('data.warnings'), 'caravana'));
        $this->assertSame(3, $response->json('data.females_count'));
    }

    public function test_a_calf_at_foot_is_neither_exposed_nor_reported_as_missing(): void
    {
        $this->cow('LSER-V-30');
        $calf = $this->cow('LSER-T-31');
        $calf->update(['category_id' => $this->ternero->id, 'teeth' => 0]);

        $response = $this->submit('ANTHONY-TORO-TEST-005', ['LSER-V-30']);

        $response->assertStatus(201);
        $this->assertSame([], $response->json('data.warnings'));
        $this->assertSame(1, $response->json('data.females_count'));
    }

    public function test_a_calf_written_on_the_sheet_is_a_row_error(): void
    {
        $this->cow('LSER-V-32');
        $calf = $this->cow('LSER-T-33');
        $calf->update(['category_id' => $this->ternero->id, 'teeth' => 0]);

        $response = $this->submit('ANTHONY-TORO-TEST-005', ['LSER-V-32', 'LSER-T-33']);

        $response->assertStatus(422);
        $this->assertStringContainsString('NOT_BREEDING_CATEGORY', (string) json_encode($response->json()));
        $this->assertNothingPersisted();
    }

    public function test_a_bull_without_evaluation_starts_the_service_on_the_owners_decision(): void
    {
        $this->cow('LSER-V-10');

        $response = $this->submit('ANTHONY-TORO-TEST-001', ['LSER-V-10']);

        $response->assertStatus(201);
        $this->assertDatabaseHas('service_order_males', [
            'service_order_id' => $response->json('data.service_order_id'),
            'male_caravan_id' => $this->caravan('ANTHONY-TORO-TEST-001')->id,
            'sanitary_status_at_entry' => null,
        ]);
    }

    public function test_a_bull_the_owner_excluded_blocks_the_whole_sheet(): void
    {
        $this->cow('LSER-V-11');
        $this->caravan('ANTHONY-TORO-TEST-004')->update([
            'is_breeding_eligible' => false,
            'breeding_exclusion_reason' => 'RESTING',
        ]);

        $response = $this->submit('ANTHONY-TORO-TEST-004', ['LSER-V-11']);

        $response->assertStatus(422);
        $this->assertSame(['BULL_NOT_FIT'], array_column($response->json('header_errors'), 'code'));
        $this->assertStringContainsString('excluido de reproducción', $response->json('header_errors.0.message'));
        $this->assertNothingPersisted();
    }

    public function test_an_excluded_female_still_in_the_batch_is_a_warning_and_is_exposed(): void
    {
        $this->cow('LSER-V-12');
        $this->cow('LSER-V-13')->update(['is_breeding_eligible' => false, 'breeding_exclusion_reason' => 'CUT_DENTITION']);

        $response = $this->submit('ANTHONY-TORO-TEST-005', ['LSER-V-12', 'LSER-V-13']);

        $response->assertStatus(201);
        $this->assertSame(['BREEDING_EXCLUDED_EXPOSED'], array_column($response->json('data.warnings'), 'code'));
        $this->assertSame(['LSER-V-13'], array_column($response->json('data.warnings'), 'caravana'));
        $this->assertSame(2, $response->json('data.females_count'));
    }

    public function test_every_row_problem_is_reported_in_a_single_response(): void
    {
        $this->cow('LSER-V-20');
        $pregnant = $this->cow('LSER-V-21');
        CaravanGestation::create(['caravan_id' => $pregnant->id, 'start_date' => now()->subMonths(2)->toDateString(), 'is_current' => true]);
        $this->cow('LSER-V-22', $this->otherBatch);

        $response = $this->submit(
            'ANTHONY-TORO-TEST-005',
            ['LSER-V-20', 'NO-EXISTE-999', 'LSER-V-21', 'LSER-V-20', '', 'ANTHONY-TORO-TEST-006', 'LSER-V-22']
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
            6 => ['NOT_IN_BATCH'],
        ], $codesByRow);

        $this->assertNothingPersisted();
    }

    public function test_a_batch_already_in_service_cannot_start_another(): void
    {
        $this->cow('LSER-V-40');

        $this->submit('ANTHONY-TORO-TEST-005', ['LSER-V-40'])->assertStatus(201);

        $response = $this->submit('ANTHONY-TORO-TEST-006', ['LSER-V-40']);

        $response->assertStatus(422);
        $this->assertContains('BATCH_NOT_ELIGIBLE', array_column($response->json('header_errors'), 'code'));
        $this->assertSame(1, ServiceOrder::withoutGlobalScopes()->where('batch_id', $this->rodeo->id)->count());
    }

    public function test_the_tenant_seed_registers_lser01(): void
    {
        $template = WorkTemplate::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('code', 'LSER-01')
            ->firstOrFail();

        $this->assertSame('REPRODUCTIVE', $template->category);
        $this->assertSame(
            ['lote_id', 'lote', 'toro_caravana', 'planned_start_date', 'planned_end_date', 'responsable', 'observaciones'],
            array_column($template->schema_definition['header_fields'], 'name')
        );
        $this->assertSame(['caravana', 'observations'], array_column($template->schema_definition['table_columns'], 'name'));
    }

    /**
     * @param list<string> $femaleTags
     * @return \Illuminate\Testing\TestResponse
     */
    private function submit(string $bullTag, array $femaleTags)
    {
        return $this->apiAs('POST', '/work-templates/lser-01/process', [
            'batch_id' => $this->rodeo->id,
            'toro_caravana' => $bullTag,
            'planned_start_date' => now()->addDays(5)->toDateString(),
            'responsable' => 'Operador de manga',
            'rows' => array_map(fn (string $tag) => ['caravana' => $tag, 'observations' => null], $femaleTags),
        ]);
    }

    private function cow(string $tag, ?Batch $batch = null): Caravan
    {
        return Caravan::create([
            'company_id' => $this->company->id,
            'batch_id' => ($batch ?? $this->rodeo)->id,
            'identification' => $tag,
            'sex' => 'H',
            'teeth' => 4,
            'category_id' => $this->vaquillona->id,
        ]);
    }

    private function caravan(string $tag): Caravan
    {
        return Caravan::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('identification', $tag)
            ->firstOrFail();
    }

    private function assertNothingPersisted(): void
    {
        $this->assertFalse(
            ServiceOrder::withoutGlobalScopes()->where('batch_id', $this->rodeo->id)->exists(),
            'Se creó una orden de servicio sobre el lote.'
        );
    }
}
