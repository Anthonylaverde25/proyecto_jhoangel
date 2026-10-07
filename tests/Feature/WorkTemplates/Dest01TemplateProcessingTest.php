<?php

declare(strict_types=1);

namespace Tests\Feature\WorkTemplates;

use Tests\Feature\DryRun\AssertsDryRun;
use App\Models\AnimalCategory;
use App\Models\Batch;
use App\Models\BatchType;
use App\Models\Caravan;
use App\Models\CaravanGestation;
use App\Models\CaravanLineage;
use App\Models\CaravanMovement;
use App\Models\CaravanWeight;
use App\Models\WorkTemplate;
use Tests\Feature\Veterinary\VeterinaryTestCase;

/**
 * DEST-01: a weaning sheet (one or several pages) weans every calf listed and moves it to the
 * weaning batch the operator declared, existing or new. All or nothing: every problem comes
 * back at once and nothing is persisted.
 */
class Dest01TemplateProcessingTest extends VeterinaryTestCase
{
    use AssertsDryRun;

    private Batch $breedingBatch;
    private AnimalCategory $vaca;
    private AnimalCategory $ternero;
    private int $weaningTypeId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->breedingBatch = Batch::create([
            'company_id' => $this->company->id,
            'name' => 'Cría Origen DEST',
            'is_active' => true,
        ]);

        $this->vaca = AnimalCategory::firstOrCreate(['code' => 'VACA'], ['name' => 'Vaca', 'sex' => 'H']);
        $this->ternero = AnimalCategory::firstOrCreate(['code' => 'TERNERO'], ['name' => 'Ternero', 'sex' => 'M']);
        $this->weaningTypeId = (int) BatchType::withoutGlobalScopes()->where('code', 'WEANING')->value('id');
    }

    public function test_a_clean_sheet_creates_the_weaning_batch_and_weans_every_calf(): void
    {
        $calves = [
            $this->nursingCalf('DEST-T-01', 'DEST-V-01', 'M'),
            $this->nursingCalf('DEST-T-02', 'DEST-V-02', 'H'),
            $this->nursingCalf('DEST-T-03', 'DEST-V-03', 'M'),
        ];

        $response = $this->submit(['new_batch_name' => 'Destete DEST Nuevo'], [
            $this->row('DEST-T-01', 170),
            $this->row('DEST-T-02', 180),
            $this->row('DEST-T-03', 190),
        ]);

        $response->assertStatus(201);
        $this->assertTrue($response->json('data.batch_created'));
        $this->assertSame(3, $response->json('data.calves_count'));
        $this->assertSame(2, $response->json('data.males_count'));
        $this->assertSame(1, $response->json('data.females_count'));
        $this->assertSame(3, $response->json('data.weighed_count'));
        $this->assertEquals(180.0, $response->json('data.average_weight'));

        $batch = Batch::withoutGlobalScopes()->findOrFail($response->json('data.batch_id'));
        $this->assertSame('Destete DEST Nuevo', $batch->name);
        $this->assertSame($this->weaningTypeId, (int) $batch->batch_type_id);
        $this->assertSame('CRIA', $batch->activity?->code);

        foreach ($calves as $calf) {
            $this->assertSame($batch->id, $calf->fresh()->batch_id);
            $this->assertFalse((bool) CaravanLineage::where('caravan_id', $calf->id)->value('is_nursing'));
            $this->assertSame(1, CaravanWeight::where('caravan_id', $calf->id)->where('current', true)->count());
            $this->assertDatabaseHas('caravan_movements', [
                'caravan_id' => $calf->id,
                'type' => 'WEANING',
                'from_batch_id' => $this->breedingBatch->id,
                'to_batch_id' => $batch->id,
            ]);
        }

        $this->assertEquals(180.0, (float) $batch->fresh()->current_weight);
    }

    public function test_a_dry_run_answers_as_the_real_sheet_and_saves_nothing(): void
    {
        $calves = [$this->nursingCalf('DEST-D-01', 'DEST-DV-01', 'M'), $this->nursingCalf('DEST-D-02', 'DEST-DV-02', 'H')];
        $sheet = fn () => $this->submit(['new_batch_name' => 'Destete DEST Dry'], [$this->row('DEST-D-01', 170), $this->row('DEST-D-02', 180)]);

        $preview = $this->assertDryRunLeavesNothing(
            ['batches', 'caravan_movements', 'caravan_weights', 'weaning_orders'],
            $sheet,
            201
        );
        $this->assertSame(2, $preview->json('data.calves_count'));
        $this->assertSame($this->breedingBatch->id, (int) $calves[0]->fresh()->batch_id);

        $saved = $sheet()->assertStatus(201);
        $this->assertNull($saved->json('dry_run'));
        $this->assertNotSame($this->breedingBatch->id, (int) $calves[0]->fresh()->batch_id);
    }

    public function test_calves_can_join_an_existing_weaning_batch(): void
    {
        $existing = Batch::create([
            'company_id' => $this->company->id,
            'name' => 'Destete DEST Existente',
            'batch_type_id' => $this->weaningTypeId,
            'observaciones' => 'Observaciones originales',
            'is_active' => true,
        ]);
        $this->nursingCalf('DEST-T-10', 'DEST-V-10', 'M');
        $this->nursingCalf('DEST-T-11', 'DEST-V-11', 'H');

        $response = $this->submit(['target_batch_id' => $existing->id], [
            $this->row('DEST-T-10', 160),
            $this->row('DEST-T-11', 165),
        ]);

        $response->assertStatus(201);
        $this->assertFalse($response->json('data.batch_created'));
        $this->assertSame($existing->id, $response->json('data.batch_id'));
        $this->assertSame(1, Batch::withoutGlobalScopes()->where('name', 'Destete DEST Existente')->count());
        $this->assertSame(2, Caravan::where('batch_id', $existing->id)->count());

        $existing->refresh();
        $this->assertSame('Destete DEST Existente', $existing->name);
        $this->assertSame('Observaciones originales', $existing->observaciones);
    }

    public function test_weight_is_optional_and_the_last_weight_stays_current(): void
    {
        $calf = $this->nursingCalf('DEST-T-20', 'DEST-V-20', 'M');
        CaravanWeight::create([
            'caravan_id' => $calf->id,
            'weight' => 35,
            'current' => true,
            'weighing_date' => now()->subMonths(6)->toDateString(),
        ]);

        $response = $this->submit(['new_batch_name' => 'Destete DEST Sin Peso'], [$this->row('DEST-T-20', null)]);

        $response->assertStatus(201);
        $this->assertSame(0, $response->json('data.weighed_count'));
        $this->assertNull($response->json('data.average_weight'));
        $this->assertFalse((bool) CaravanLineage::where('caravan_id', $calf->id)->value('is_nursing'));
        $this->assertSame(1, CaravanWeight::where('caravan_id', $calf->id)->count());
        $this->assertEquals(35.0, (float) CaravanWeight::where('caravan_id', $calf->id)->where('current', true)->value('weight'));
    }

    public function test_the_mother_tag_is_not_validated_and_the_mother_is_left_untouched(): void
    {
        $calf = $this->nursingCalf('DEST-T-30', 'DEST-V-30', 'H');
        $mother = Caravan::where('identification', 'DEST-V-30')->firstOrFail();

        $response = $this->submit(['new_batch_name' => 'Destete DEST Madre'], [
            ['caravana' => 'DEST-T-30', 'caravana_madre' => 'OTRA-VACA-999', 'peso' => 150, 'observations' => null],
        ]);

        $response->assertStatus(201);
        $this->assertFalse((bool) CaravanLineage::where('caravan_id', $calf->id)->value('is_nursing'));
        $this->assertSame($this->breedingBatch->id, $mother->fresh()->batch_id);
        $this->assertSame(0, CaravanMovement::where('caravan_id', $mother->id)->count());
    }

    public function test_every_row_problem_is_reported_in_a_single_response(): void
    {
        $this->nursingCalf('DEST-T-40', 'DEST-V-40', 'M');
        $weaned = $this->nursingCalf('DEST-T-41', 'DEST-V-41', 'M');
        CaravanLineage::where('caravan_id', $weaned->id)->update(['is_nursing' => false]);
        $this->caravan('DEST-SIN-LINAJE', 'M', $this->ternero);

        $response = $this->submit(['new_batch_name' => 'Destete DEST Errores'], [
            $this->row('DEST-T-40', 170),
            $this->row('NO-EXISTE-999', 170),
            $this->row('DEST-T-41', 170),
            $this->row('DEST-T-40', 170),
            $this->row('', null),
            $this->row('DEST-SIN-LINAJE', null),
        ]);

        $response->assertStatus(422);
        $this->assertSame([], $response->json('header_errors'));

        $codesByRow = collect($response->json('row_errors'))
            ->mapWithKeys(fn ($row) => [$row['row_index'] => array_column($row['errors'], 'code')])
            ->all();

        $this->assertSame([
            1 => ['NOT_FOUND'],
            2 => ['ALREADY_WEANED'],
            3 => ['DUPLICATED_IN_SHEET'],
            5 => ['NO_LINEAGE'],
        ], $codesByRow);

        $this->assertNothingPersisted('Destete DEST Errores');
    }

    public function test_a_weight_of_zero_or_less_is_rejected(): void
    {
        $this->nursingCalf('DEST-T-60', 'DEST-V-60', 'M');
        $this->nursingCalf('DEST-T-61', 'DEST-V-61', 'M');

        $response = $this->submit(['new_batch_name' => 'Destete DEST Peso Cero'], [
            $this->row('DEST-T-60', 0),
            $this->row('DEST-T-61', 172),
        ]);

        $response->assertStatus(422);
        $this->assertSame(['INVALID_WEIGHT'], array_column($response->json('row_errors.0.errors'), 'code'));
        $this->assertSame(0, $response->json('row_errors.0.row_index'));
        $this->assertNothingPersisted('Destete DEST Peso Cero');
    }

    public function test_a_weight_far_from_the_troop_is_warned_and_still_saved(): void
    {
        foreach (['70', '71', '72', '73'] as $n) {
            $this->nursingCalf("DEST-T-{$n}", "DEST-V-{$n}", 'M');
        }

        $response = $this->submit(['new_batch_name' => 'Destete DEST Peso Raro'], [
            $this->row('DEST-T-70', 172),
            $this->row('DEST-T-71', 1850),
            $this->row('DEST-T-72', 185.5),
            $this->row('DEST-T-73', 176),
        ]);

        // The person reviewing the sheet saw it marked and confirmed: it is a warning, not a block.
        $response->assertStatus(201);
        $warnings = $response->json('data.warnings');
        $this->assertSame(['WEIGHT_OUTLIER'], array_column($warnings, 'code'));
        $this->assertStringContainsString("'DEST-T-71' (1.850 kg)", $warnings[0]['message']);
        $this->assertStringContainsString('mediana 180,8 kg', $warnings[0]['message']);
    }

    public function test_the_declared_destination_is_checked(): void
    {
        $this->nursingCalf('DEST-T-50', 'DEST-V-50', 'M');
        $rows = [$this->row('DEST-T-50', 170)];

        Batch::create([
            'company_id' => $this->company->id,
            'name' => 'Destete DEST Ocupado',
            'batch_type_id' => $this->weaningTypeId,
            'is_active' => true,
        ]);
        $inUse = $this->submit(['new_batch_name' => 'Destete DEST Ocupado'], $rows);
        $inUse->assertStatus(422);
        $this->assertSame(['BATCH_NAME_IN_USE'], array_column($inUse->json('header_errors'), 'code'));
        $this->assertStringContainsString('Elíjalo como lote existente', $inUse->json('header_errors.0.message'));

        $notWeaning = $this->submit(['target_batch_id' => $this->breedingBatch->id], $rows);
        $notWeaning->assertStatus(422);
        $this->assertSame(['BATCH_NOT_FOUND'], array_column($notWeaning->json('header_errors'), 'code'));

        $missing = $this->submit([], $rows);
        $missing->assertStatus(422);
        $this->assertSame(['BATCH_TARGET_MISSING'], array_column($missing->json('header_errors'), 'code'));
        $this->assertSame('lote_destete', $missing->json('header_errors.0.field'));
        $this->submit(['target_batch_id' => $this->breedingBatch->id, 'new_batch_name' => 'Doble'], $rows)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['target_batch_id']);

        $this->assertSame(0, CaravanMovement::where('type', 'WEANING')->count());
    }

    public function test_a_new_weaning_batch_may_share_the_name_of_a_batch_of_another_type(): void
    {
        $this->nursingCalf('DEST-T-51', 'DEST-V-51', 'M');

        // The sheet names the breeding batch itself: the calves go to a NEW weaning batch with that
        // name, and the shared name is only advised to change.
        $response = $this->submit(['new_batch_name' => 'Cría Origen DEST'], [$this->row('DEST-T-51', 170)]);

        $response->assertStatus(201);
        $this->assertTrue($response->json('data.batch_created'));
        $this->assertNotSame($this->breedingBatch->id, $response->json('data.batch_id'));
        $created = Batch::withoutGlobalScopes()->findOrFail($response->json('data.batch_id'));
        $this->assertSame($this->weaningTypeId, (int) $created->batch_type_id);
        $warnings = $response->json('data.warnings');
        $this->assertSame(['BATCH_NAME_SHARED'], array_column($warnings, 'code'));
        $this->assertStringContainsString('Conviene darle otro nombre', $warnings[0]['message']);
    }

    public function test_dates_are_checked_against_today_and_the_birth(): void
    {
        $this->nursingCalf('DEST-T-60', 'DEST-V-60', 'M', now()->subMonths(2)->toDateString());

        $future = $this->submit(['new_batch_name' => 'Destete DEST Futuro', 'fecha_destete' => now()->addDay()->toDateString()], [$this->row('DEST-T-60', 90)]);
        $future->assertStatus(422);
        $this->assertSame(['FUTURE_DATE'], array_column($future->json('header_errors'), 'code'));

        $beforeBirth = $this->submit(['new_batch_name' => 'Destete DEST Antes', 'fecha_destete' => now()->subMonths(3)->toDateString()], [$this->row('DEST-T-60', 90)]);
        $beforeBirth->assertStatus(422);
        $this->assertSame('WEANING_BEFORE_BIRTH', $beforeBirth->json('row_errors.0.errors.0.code'));
    }

    public function test_a_sheet_with_only_blank_lines_is_rejected(): void
    {
        $response = $this->submit(['new_batch_name' => 'Destete DEST Vacío'], [$this->row('', null), $this->row('', null)]);

        $response->assertStatus(422);
        $this->assertSame(['NO_CALVES'], array_column($response->json('header_errors'), 'code'));
    }

    public function test_the_tenant_seed_registers_dest01(): void
    {
        $template = WorkTemplate::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('code', 'DEST-01')
            ->firstOrFail();

        $this->assertSame('WEANING', $template->category);
        $this->assertSame(
            ['orden_destete', 'lote_destete', 'sistema_manejo', 'fecha_destete', 'tipo_destete', 'lote_origen', 'responsable', 'hoja_numero', 'hoja_total', 'observaciones'],
            array_column($template->schema_definition['header_fields'], 'name')
        );
        $columns = collect($template->schema_definition['table_columns'])->keyBy('name');
        $this->assertSame(
            ['caravana', 'caravana_madre', 'categoria', 'cs_nueva', 'peso', 'lote_destino', 'manejo', 'observations'],
            $columns->keys()->all()
        );
        // The batch of each calf is never completed from the header.
        $this->assertStringContainsString('NO completar con el encabezado', $columns['lote_destino']['ai_hint']);
        $this->assertFalse($columns['peso']['required']);
        $this->assertFalse($columns['caravana_madre']['required']);
    }

    /**
     * @param array<string, mixed> $header
     * @param list<array<string, mixed>> $rows
     * @return \Illuminate\Testing\TestResponse
     */
    private function submit(array $header, array $rows)
    {
        return $this->apiAs('POST', '/work-templates/dest-01/process', [
            'fecha_destete' => now()->toDateString(),
            'tipo_destete' => 'TRADICIONAL',
            'responsable' => 'Operador de manga',
            // The box of the header: the management system of the one new weaning batch.
            'sistema_manejo' => 'PASTURA',
            ...$header,
            'rows' => $rows,
        ]);
    }

    /**
     * @return array{caravana: string, caravana_madre: null, peso: int|float|null, observations: null}
     */
    private function row(string $tag, int|float|null $weight): array
    {
        return ['caravana' => $tag, 'caravana_madre' => null, 'peso' => $weight, 'observations' => null];
    }

    private function nursingCalf(string $calfTag, string $motherTag, string $sex, ?string $birthDate = null): Caravan
    {
        $birthDate ??= now()->subMonths(7)->toDateString();

        $mother = $this->caravan($motherTag, 'H', $this->vaca);
        $gestation = CaravanGestation::create([
            'caravan_id' => $mother->id,
            'start_date' => now()->subMonths(16)->toDateString(),
            'is_current' => false,
            'success' => true,
            'end_date' => $birthDate,
        ]);

        $calf = $this->caravan($calfTag, $sex, $this->ternero);
        CaravanLineage::create([
            'caravan_id' => $calf->id,
            'mother_id' => $mother->id,
            'gestation_id' => $gestation->id,
            'birth_date' => $birthDate,
            'is_nursing' => true,
        ]);

        return $calf;
    }

    private function caravan(string $tag, string $sex, AnimalCategory $category): Caravan
    {
        return Caravan::create([
            'company_id' => $this->company->id,
            'batch_id' => $this->breedingBatch->id,
            'identification' => $tag,
            'sex' => $sex,
            'category_id' => $category->id,
        ]);
    }

    private function assertNothingPersisted(string $batchName): void
    {
        $this->assertFalse(Batch::withoutGlobalScopes()->where('name', $batchName)->exists(), "Se creó el lote '{$batchName}'.");
        $this->assertSame(0, CaravanMovement::where('type', 'WEANING')->count());
    }
}
