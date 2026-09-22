<?php

declare(strict_types=1);

namespace Tests\Feature\WorkTemplates;

use App\Models\Activity;
use App\Models\AnimalCategory;
use App\Models\Batch;
use App\Models\BatchType;
use App\Models\BatchWeight;
use App\Models\Caravan;
use App\Models\CaravanMovement;
use App\Models\CaravanWeight;
use Tests\Feature\Veterinary\VeterinaryTestCase;

/**
 * CACT-01: a change-of-activity sheet moves every animal listed to the destination the
 * operator confirmed, and records the measurements taken at the chute on the way.
 *
 * The order of the writes is the point of the whole design, so most of what is asserted
 * here is the shape of the weight series the sheet leaves behind.
 */
class Cact01TemplateProcessingTest extends VeterinaryTestCase
{
    private Batch $sourceBatch;
    private AnimalCategory $ternero;
    private int $operationalTypeId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sourceBatch = Batch::create([
            'company_id' => $this->company->id,
            'name' => 'Cría Origen CACT',
            'activity_id' => $this->activityId('CRIA'),
            'is_active' => true,
        ]);

        $this->ternero = AnimalCategory::firstOrCreate(['code' => 'TERNERO'], ['name' => 'Ternero', 'sex' => 'M']);
        $this->operationalTypeId = (int) BatchType::withoutGlobalScopes()->where('code', 'OPERATIONAL')->value('id');
    }

    // ---------------------------------------------------------------- happy paths

    public function test_a_clean_sheet_creates_the_destination_and_moves_every_animal(): void
    {
        $animals = [
            $this->animal('CACT-01', 200, 2),
            $this->animal('CACT-02', 210, 2),
            $this->animal('CACT-03', 205, 2),
            $this->animal('CACT-04', 198, 2),
            $this->animal('CACT-05', 215, 2),
        ];

        $response = $this->submit(
            [$this->newDestination('RECRIA NORTE', 'Recría Norte', 'RECRIA', isConfined: true)],
            [
                $this->row('CACT-01', 'RECRIA NORTE', 240, '4D'),
                $this->row('CACT-02', 'RECRIA NORTE', 250, '4D'),
                $this->row('CACT-03', 'RECRIA NORTE', 245, '4D'),
                $this->row('CACT-04', 'RECRIA NORTE', 238, '4D'),
                $this->row('CACT-05', 'RECRIA NORTE', 255, '4D'),
            ]
        );

        $response->assertStatus(201);

        $destination = $response->json('data.destinations.0');
        $this->assertTrue($destination['created']);
        $this->assertSame(5, $destination['count']);

        $batch = Batch::withoutGlobalScopes()->findOrFail($destination['batch_id']);
        $this->assertSame('Recría Norte', $batch->name);
        $this->assertTrue((bool) $batch->is_confined);
        $this->assertSame('RECRIA', $batch->activity?->code);

        foreach ($animals as $animal) {
            $this->assertSame($batch->id, $animal->fresh()->batch_id);
            $this->assertSame(4, (int) $animal->fresh()->teeth, 'La dentición leída avanza');
            $this->assertSame(1, CaravanWeight::where('caravan_id', $animal->id)->where('current', true)->count());

            $movement = CaravanMovement::where('caravan_id', $animal->id)->where('type', 'TRANSFER')->first();
            $this->assertNotNull($movement);
            $this->assertSame($this->sourceBatch->id, (int) $movement->from_batch_id);
            $this->assertSame($batch->id, (int) $movement->to_batch_id);
        }

        // The old weights are kept as history, only unflagged.
        $this->assertSame(2, CaravanWeight::where('caravan_id', $animals[0]->id)->count());
    }

    public function test_one_source_closes_once_no_matter_how_many_destinations(): void
    {
        foreach (['CACT-10', 'CACT-11', 'CACT-12', 'CACT-13', 'CACT-14', 'CACT-15'] as $tag) {
            $this->animal($tag, 200, 2);
        }

        $existingA = $this->existingBatch('Recría Este', 'RECRIA', true);
        $existingB = $this->existingBatch('Invernada Sur', 'INVERNADA', false);

        $before = $this->seriesCount($this->sourceBatch->id);

        $response = $this->submit(
            [
                ['key' => 'RECRIA ESTE', 'target_batch_id' => $existingA->id, 'new_batch' => null],
                ['key' => 'INVERNADA SUR', 'target_batch_id' => $existingB->id, 'new_batch' => null],
                $this->newDestination('RECRIA OESTE', 'Recría Oeste', 'RECRIA', isConfined: false),
            ],
            [
                $this->row('CACT-10', 'RECRIA ESTE', 240, null),
                $this->row('CACT-11', 'RECRIA ESTE', 245, null),
                $this->row('CACT-12', 'INVERNADA SUR', 250, null),
                $this->row('CACT-13', 'INVERNADA SUR', 255, null),
                $this->row('CACT-14', 'RECRIA OESTE', 260, null),
                $this->row('CACT-15', 'RECRIA OESTE', 265, null),
            ]
        );

        $response->assertStatus(201);
        $this->assertCount(3, $response->json('data.destinations'));

        // Exactly three new points on the source: the closing CONTROL, the weighing
        // CONTROL and ONE MOVEMENT_OUT. Not one per destination.
        $series = $this->series($this->sourceBatch->id);
        $new = array_slice($series, $before);

        $this->assertCount(3, $new, 'El lote de origen debe cerrar una sola vez');
        $this->assertSame(['CONTROL', 'CONTROL', 'MOVEMENT_OUT'], array_column($new, 'type'));

        foreach ([$existingA, $existingB] as $batch) {
            $this->assertSame(
                1,
                BatchWeight::where('batch_id', $batch->id)->where('type', 'MOVEMENT_IN')->count()
            );
        }
    }

    /**
     * What the weighing point measures is the SOURCE BATCH, not the troop leaving it.
     * The aggregate is derived from every animal the batch holds at that instant, and at
     * that instant it still holds the ones staying behind.
     */
    public function test_the_weighing_point_measures_the_whole_source_batch(): void
    {
        $moving = [];
        foreach (['CACT-20', 'CACT-21', 'CACT-22', 'CACT-23'] as $tag) {
            $moving[] = $this->animal($tag, 100, 0);
        }
        foreach (['CACT-24', 'CACT-25', 'CACT-26', 'CACT-27', 'CACT-28', 'CACT-29'] as $tag) {
            $this->animal($tag, 100, 0);
        }

        $before = $this->seriesCount($this->sourceBatch->id);

        $this->submit(
            [$this->newDestination('RECRIA MIXTA', 'Recría Mixta', 'RECRIA', isConfined: false)],
            [
                $this->row('CACT-20', 'RECRIA MIXTA', 200, null),
                $this->row('CACT-21', 'RECRIA MIXTA', 200, null),
                $this->row('CACT-22', 'RECRIA MIXTA', 200, null),
                $this->row('CACT-23', 'RECRIA MIXTA', 200, null),
            ]
        )->assertStatus(201);

        $new = array_slice($this->series($this->sourceBatch->id), $before);
        [$closing, $weighing, $movementOut] = $new;

        $this->assertSame(10, (int) $closing['caravans_count']);
        $this->assertEqualsWithDelta(1000.0, (float) $closing['total_weight'], 0.01);

        // Still ten head: nobody has moved yet.
        $this->assertSame(10, (int) $weighing['caravans_count'], 'El pesaje ocurre antes del movimiento');
        // Four fresh weights plus six stale ones, NOT the four alone.
        $this->assertEqualsWithDelta(1400.0, (float) $weighing['total_weight'], 0.01);

        $this->assertSame('MOVEMENT_OUT', $movementOut['type']);
        $this->assertSame(6, (int) $movementOut['caravans_count']);
    }

    public function test_a_row_without_weight_keeps_the_previous_one(): void
    {
        $animal = $this->animal('CACT-30', 180, 2);

        $this->submit(
            [$this->newDestination('RECRIA SIN PESO', 'Recría Sin Peso', 'RECRIA', isConfined: false)],
            [$this->row('CACT-30', 'RECRIA SIN PESO', null, null)]
        )->assertStatus(201);

        $this->assertSame(1, CaravanWeight::where('caravan_id', $animal->id)->count());
        $this->assertEqualsWithDelta(
            180.0,
            (float) CaravanWeight::where('caravan_id', $animal->id)->where('current', true)->value('weight'),
            0.01
        );
    }

    /**
     * recalculateBatchWeight() has no same-day guard, so an unweighed sheet would write a
     * CONTROL identical to the closing point: a dot that looks like a weighing and is not.
     */
    public function test_a_sheet_with_no_weights_writes_no_weighing_point(): void
    {
        foreach (['CACT-40', 'CACT-41', 'CACT-42', 'CACT-43'] as $tag) {
            $this->animal($tag, 190, 2);
        }

        $before = $this->seriesCount($this->sourceBatch->id);

        $this->submit(
            [$this->newDestination('RECRIA SECA', 'Recría Seca', 'RECRIA', isConfined: false)],
            [
                $this->row('CACT-40', 'RECRIA SECA', null, null),
                $this->row('CACT-41', 'RECRIA SECA', null, null),
                $this->row('CACT-42', 'RECRIA SECA', null, null),
                $this->row('CACT-43', 'RECRIA SECA', null, null),
            ]
        )->assertStatus(201);

        $new = array_slice($this->series($this->sourceBatch->id), $before);

        $this->assertCount(2, $new, 'Sin pesos no se escribe el punto de pesaje');
        $this->assertSame(['CONTROL', 'MOVEMENT_OUT'], array_column($new, 'type'));
    }

    // ---------------------------------------------------------------- dentition

    public function test_dentition_only_advances_and_an_unreadable_cell_is_an_error(): void
    {
        $advancing = $this->animal('CACT-50', 200, 2);
        $regressing = $this->animal('CACT-51', 200, 6);
        $blank = $this->animal('CACT-52', 200, 6);

        $response = $this->submit(
            [$this->newDestination('RECRIA DIENTES', 'Recría Dientes', 'RECRIA', isConfined: false)],
            [
                $this->row('CACT-50', 'RECRIA DIENTES', 240, '4D'),
                $this->row('CACT-51', 'RECRIA DIENTES', 240, '2D'),
                $this->row('CACT-52', 'RECRIA DIENTES', 240, null),
            ]
        );

        $response->assertStatus(201);

        $this->assertSame(4, (int) $advancing->fresh()->teeth);
        $this->assertSame(6, (int) $regressing->fresh()->teeth, 'La dentición no retrocede');
        $this->assertSame(6, (int) $blank->fresh()->teeth, 'Una celda vacía no toca nada');

        $codes = array_column($response->json('data.warnings'), 'code');
        $this->assertContains('TEETH_REGRESSION', $codes);
    }

    public function test_unreadable_dentition_is_rejected_per_row(): void
    {
        $this->animal('CACT-55', 200, 2);
        $this->animal('CACT-56', 200, 2);

        $response = $this->submit(
            [$this->newDestination('RECRIA ILEGIBLE', 'Recría Ilegible', 'RECRIA', isConfined: false)],
            [
                $this->row('CACT-55', 'RECRIA ILEGIBLE', 240, 'xyz'),
                $this->row('CACT-56', 'RECRIA ILEGIBLE', 240, '5'),
            ]
        );

        $response->assertStatus(422);

        foreach ($response->json('row_errors') as $rowError) {
            $this->assertSame(['INVALID_TEETH'], array_column($rowError['errors'], 'code'));
        }

        $this->assertNothingPersisted('Recría Ilegible');
    }

    // ---------------------------------------------------------------- all or nothing

    public function test_one_unknown_animal_rolls_back_the_whole_sheet(): void
    {
        $known = $this->animal('CACT-60', 200, 2);

        $response = $this->submit(
            [$this->newDestination('RECRIA ROTA', 'Recría Rota', 'RECRIA', isConfined: false)],
            [
                $this->row('CACT-60', 'RECRIA ROTA', 240, '4D'),
                $this->row('CACT-NO-EXISTE', 'RECRIA ROTA', 240, '4D'),
            ]
        );

        $response->assertStatus(422);
        $this->assertSame('NOT_FOUND', $response->json('row_errors.0.errors.0.code'));

        $this->assertNothingPersisted('Recría Rota');
        $this->assertSame($this->sourceBatch->id, $known->fresh()->batch_id);
        $this->assertSame(2, (int) $known->fresh()->teeth, 'No se tocó la dentición');
        $this->assertSame(1, CaravanWeight::where('caravan_id', $known->id)->count());
    }

    /**
     * Re-scanning the same sheet cannot double anything. It does not need a marker of its
     * own: the animals are no longer in the source batch, so every row fails validation
     * before a single write happens.
     */
    public function test_submitting_the_same_sheet_twice_writes_nothing_the_second_time(): void
    {
        $animal = $this->animal('CACT-70', 200, 2);

        $destinations = [$this->newDestination('RECRIA REPETIDA', 'Recría Repetida', 'RECRIA', isConfined: false)];
        $rows = [$this->row('CACT-70', 'RECRIA REPETIDA', 240, '4D')];

        $this->submit($destinations, $rows)->assertStatus(201);

        $weightsAfterFirst = CaravanWeight::where('caravan_id', $animal->id)->count();
        $movementsAfterFirst = CaravanMovement::where('caravan_id', $animal->id)->count();

        $repeat = $this->submit(
            [$this->newDestination('RECRIA REPETIDA 2', 'Recría Repetida 2', 'RECRIA', isConfined: false)],
            [$this->row('CACT-70', 'RECRIA REPETIDA 2', 240, '4D')]
        );

        $repeat->assertStatus(422);
        $this->assertSame('NOT_IN_SOURCE_BATCH', $repeat->json('row_errors.0.errors.0.code'));

        $this->assertSame($weightsAfterFirst, CaravanWeight::where('caravan_id', $animal->id)->count());
        $this->assertSame($movementsAfterFirst, CaravanMovement::where('caravan_id', $animal->id)->count());
        $this->assertNothingPersisted('Recría Repetida 2');
    }

    // ---------------------------------------------------------------- management system

    public function test_a_new_cria_batch_must_declare_its_management_system(): void
    {
        $this->animal('CACT-80', 200, 2);

        $response = $this->submit(
            [$this->newDestination('CRIA NUEVA', 'Cría Nueva', 'CRIA', isConfined: null)],
            [$this->row('CACT-80', 'CRIA NUEVA', 240, null)]
        );

        $response->assertStatus(422);
        $this->assertContains('MANAGEMENT_SYSTEM_MISSING', array_column($response->json('header_errors'), 'code'));
        $this->assertNothingPersisted('Cría Nueva');
    }

    public function test_an_existing_destination_keeps_its_management_system(): void
    {
        $this->animal('CACT-85', 200, 2);
        $existing = $this->existingBatch('Recría Declarada', 'RECRIA', true);

        $response = $this->submit(
            [['key' => 'RECRIA DECLARADA', 'target_batch_id' => $existing->id, 'new_batch' => null]],
            [$this->row('CACT-85', 'RECRIA DECLARADA', 240, null)],
            ['sistema_manejo' => 'PASTURA']
        );

        $response->assertStatus(201);
        $this->assertContains('MANAGEMENT_SYSTEM_DIFFERS', array_column($response->json('data.warnings'), 'code'));
        $this->assertTrue((bool) $existing->fresh()->is_confined, 'La planilla no pisa el manejo de un lote existente');
    }

    public function test_an_undeclared_destination_is_reported_and_left_alone(): void
    {
        $this->animal('CACT-86', 200, 2);
        $existing = $this->existingBatch('Recría Sin Declarar', 'RECRIA', null);

        $response = $this->submit(
            [['key' => 'RECRIA SIN DECLARAR', 'target_batch_id' => $existing->id, 'new_batch' => null]],
            [$this->row('CACT-86', 'RECRIA SIN DECLARAR', 240, null)],
            ['sistema_manejo' => 'CORRAL']
        );

        $response->assertStatus(201);
        $this->assertContains('MANAGEMENT_SYSTEM_UNDECLARED', array_column($response->json('data.warnings'), 'code'));
        $this->assertNull($existing->fresh()->is_confined, 'La planilla no completa el manejo de un lote existente');
    }

    // ---------------------------------------------------------------- destinations

    public function test_the_source_batch_cannot_be_its_own_destination(): void
    {
        $this->animal('CACT-90', 200, 2);

        $response = $this->submit(
            [['key' => 'ORIGEN', 'target_batch_id' => $this->sourceBatch->id, 'new_batch' => null]],
            [$this->row('CACT-90', 'ORIGEN', 240, null)]
        );

        $response->assertStatus(422);
        $this->assertContains('SAME_BATCH', array_column($response->json('header_errors'), 'code'));
    }

    public function test_two_keys_pointing_at_the_same_batch_are_rejected(): void
    {
        $this->animal('CACT-95', 200, 2);
        $this->animal('CACT-96', 200, 2);
        $existing = $this->existingBatch('Recría Duplicada', 'RECRIA', true);

        $response = $this->submit(
            [
                ['key' => 'RECRIA DUPLICADA', 'target_batch_id' => $existing->id, 'new_batch' => null],
                ['key' => 'RECRIA  DUPLICADA', 'target_batch_id' => $existing->id, 'new_batch' => null],
            ],
            [
                $this->row('CACT-95', 'RECRIA DUPLICADA', 240, null),
                $this->row('CACT-96', 'RECRIA  DUPLICADA', 240, null),
            ]
        );

        $response->assertStatus(422);
        $this->assertContains('DUPLICATED_DESTINATION', array_column($response->json('header_errors'), 'code'));
    }

    /**
     * A destination that was declared and then rejected already has a header error saying
     * why. Repeating it once per animal would bury the one message that matters under a
     * hundred that do not.
     */
    public function test_a_rejected_destination_does_not_cascade_into_every_row(): void
    {
        $existing = $this->existingBatch('Recría Ya Existente', 'RECRIA', true);

        foreach (['CACT-D1', 'CACT-D2', 'CACT-D3'] as $tag) {
            $this->animal($tag, 200, 2);
        }

        // A new batch whose name collides with the one above: one header error.
        $response = $this->submit(
            [$this->newDestination('RECRÍA YA EXISTENTE', $existing->name, 'RECRIA', isConfined: true)],
            [
                $this->row('CACT-D1', 'RECRÍA YA EXISTENTE', 240, null),
                $this->row('CACT-D2', 'RECRÍA YA EXISTENTE', 245, null),
                $this->row('CACT-D3', 'RECRÍA YA EXISTENTE', 250, null),
            ]
        );

        $response->assertStatus(422);
        $this->assertContains('BATCH_NAME_IN_USE', array_column($response->json('header_errors'), 'code'));
        $this->assertSame([], $response->json('row_errors'), 'El error del destino no se repite por fila');
    }

    public function test_a_duplicated_tag_across_pages_is_rejected(): void
    {
        $this->animal('CACT-97', 200, 2);

        $response = $this->submit(
            [$this->newDestination('RECRIA DOBLE', 'Recría Doble', 'RECRIA', isConfined: false)],
            [
                $this->row('CACT-97', 'RECRIA DOBLE', 240, null),
                $this->row('CACT-97', 'RECRIA DOBLE', 245, null),
            ]
        );

        $response->assertStatus(422);
        $this->assertSame('DUPLICATED_IN_SHEET', $response->json('row_errors.0.errors.0.code'));
    }

    // ---------------------------------------------------------------- advisory only

    public function test_mismatched_activity_sex_and_totals_warn_without_blocking(): void
    {
        $this->animal('CACT-A1', 200, 2, 'M');

        $response = $this->submit(
            [$this->newDestination('RECRIA AVISOS', 'Recría Avisos', 'RECRIA', isConfined: false)],
            [array_merge($this->row('CACT-A1', 'RECRIA AVISOS', 240, null), ['sexo' => 'H'])],
            [
                'actividad_origen' => 'Invernada',
                'total_cabezas' => 5,
                'peso_total' => 1200,
            ]
        );

        $response->assertStatus(201);

        $codes = array_column($response->json('data.warnings'), 'code');
        $this->assertContains('ACTIVITY_MISMATCH', $codes);
        $this->assertContains('SEX_MISMATCH', $codes);
        $this->assertContains('SHEET_TOTAL_MISMATCH', $codes);
    }

    /**
     * Rows and destinations join on a key read off paper. A scan that drops an accent or
     * doubles a space must not leave the row pointing at a destination nobody declared.
     */
    public function test_a_destination_key_joins_across_accents_and_spacing(): void
    {
        $this->animal('CACT-N1', 200, 2);
        $this->animal('CACT-N2', 200, 2);

        $response = $this->submit(
            [$this->newDestination('Recría  Norte', 'Recría Norte', 'RECRIA', isConfined: true)],
            [
                $this->row('CACT-N1', 'RECRIA NORTE', 240, null),
                $this->row('CACT-N2', 'recria norte', 245, null),
            ]
        );

        $response->assertStatus(201);
        $this->assertSame(2, $response->json('data.destinations.0.count'), 'Las dos filas caen en el mismo destino');
    }

    public function test_the_tenant_seed_registers_cact01_and_archives_op02(): void
    {
        $template = \App\Models\WorkTemplate::where('code', 'CACT-01')->first();

        $this->assertNotNull($template);
        $this->assertSame('ACTIVITY', $template->category);
        $this->assertSame('active', $template->status);

        $columns = collect($template->schema_definition['table_columns'])->keyBy('name');
        $this->assertTrue($columns->has('peso_actual'));
        $this->assertTrue($columns->has('dientes'));
        $this->assertTrue($columns->has('lote_destino'));
        $this->assertFalse($columns->has('estado_corporal'), 'El estado corporal quedó fuera de alcance');

        $this->assertSame('archived', \App\Models\WorkTemplate::where('code', 'OP-02')->value('status'));
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @param list<array<string, mixed>> $destinations
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed> $header
     * @return \Illuminate\Testing\TestResponse
     */
    private function submit(array $destinations, array $rows, array $header = [])
    {
        return $this->apiAs('POST', '/work-templates/cact-01/process', [
            'source_batch_id' => $this->sourceBatch->id,
            'fecha_movimiento' => now()->toDateString(),
            'responsable' => 'Operador de manga',
            ...$header,
            'destinations' => $destinations,
            'rows' => $rows,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $tag, string $destinationKey, int|float|null $weight, ?string $teeth): array
    {
        return [
            'caravana' => $tag,
            'peso_actual' => $weight,
            'sexo' => null,
            'categoria' => null,
            'dientes' => $teeth,
            'destination_key' => $destinationKey,
            'observations' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function newDestination(string $key, string $name, string $activityCode, ?bool $isConfined): array
    {
        return [
            'key' => $key,
            'target_batch_id' => null,
            'new_batch' => [
                'name' => $name,
                'activity_id' => $this->activityId($activityCode),
                'batch_type_id' => $this->operationalTypeId,
                'is_confined' => $isConfined,
            ],
        ];
    }

    private function existingBatch(string $name, string $activityCode, ?bool $isConfined): Batch
    {
        return Batch::create([
            'company_id' => $this->company->id,
            'name' => $name,
            'activity_id' => $this->activityId($activityCode),
            'batch_type_id' => $this->operationalTypeId,
            'is_confined' => $isConfined,
            'is_active' => true,
        ]);
    }

    private function animal(string $tag, float $weight, int $teeth, string $sex = 'M'): Caravan
    {
        $caravan = Caravan::create([
            'company_id' => $this->company->id,
            'batch_id' => $this->sourceBatch->id,
            'identification' => $tag,
            'sex' => $sex,
            'teeth' => $teeth,
            'category_id' => $this->ternero->id,
        ]);

        CaravanWeight::create([
            'caravan_id' => $caravan->id,
            'weight' => $weight,
            'current' => true,
            'weighing_date' => now()->subMonths(3)->toDateString(),
        ]);

        return $caravan;
    }

    private function activityId(string $code): int
    {
        return (int) Activity::withoutGlobalScopes()->where('code', $code)->value('id');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function series(int $batchId): array
    {
        return BatchWeight::where('batch_id', $batchId)
            ->orderBy('weighing_date')
            ->orderBy('id')
            ->get()
            ->map(fn ($row) => $row->toArray())
            ->all();
    }

    private function seriesCount(int $batchId): int
    {
        return BatchWeight::where('batch_id', $batchId)->count();
    }

    private function assertNothingPersisted(string $batchName): void
    {
        $this->assertFalse(
            Batch::withoutGlobalScopes()->where('name', $batchName)->exists(),
            "Se creó el lote '{$batchName}' pese al error."
        );
    }
}
