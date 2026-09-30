<?php

declare(strict_types=1);

namespace Tests\Feature\TransferOrders;

use App\Models\Activity;
use App\Models\AnimalCategory;
use App\Models\AnimalSubcategory;
use App\Models\Batch;
use App\Models\BatchType;
use App\Models\BatchWeight;
use App\Models\Caravan;
use App\Models\CaravanMovement;
use App\Models\CaravanWeight;
use App\Models\TransferOrder;
use Tests\Feature\Veterinary\VeterinaryTestCase;

/**
 * "Registrar transferencia": a movement that already happened in the field, loaded afterwards
 * as an order born executed and dated on the day it happened.
 */
class RegisterTransferTest extends VeterinaryTestCase
{
    private Batch $source;
    private int $operationalTypeId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->source = Batch::create([
            'company_id' => $this->company->id,
            'name' => 'Cría Origen REG',
            'activity_id' => $this->activityId('CRIA'),
            'is_active' => true,
        ]);

        $this->operationalTypeId = (int) BatchType::withoutGlobalScopes()->where('code', 'OPERATIONAL')->value('id');
    }

    public function test_registering_to_an_existing_batch_is_born_executed_and_moves_the_animals(): void
    {
        $animals = $this->animals(['REG-A1', 'REG-A2']);
        $target = $this->existingBatch('Recría Registrada REG');

        $response = $this->register($animals, ['target_batch_id' => $target->id]);

        $response->assertStatus(201);
        $this->assertSame('EXECUTED', $response->json('order.status'));
        $this->assertSame('REGISTERED', $response->json('order.kind'));
        $this->assertSame('Registrada', $response->json('order.kind_label'));
        $this->assertSame(2, $response->json('order.moved_head_count'));
        $this->assertSame(0, $response->json('order.pending_head_count'));

        foreach ($animals as $animal) {
            $this->assertSame($target->id, (int) $animal->fresh()->batch_id);
        }
    }

    public function test_the_movement_keeps_the_declared_date_and_the_order_its_loading_date(): void
    {
        $yesterday = now()->subDay()->toDateString();
        $animals = $this->animals(['REG-D1']);
        $target = $this->existingBatch('Recría Ayer REG');

        $response = $this->register($animals, ['target_batch_id' => $target->id], $yesterday);

        $response->assertStatus(201);
        $this->assertSame($yesterday, $response->json('order.movement_date'));
        $this->assertSame(now()->toDateString(), substr((string) $response->json('order.created_at'), 0, 10));

        $movement = CaravanMovement::where('caravan_id', $animals[0]->id)->where('type', 'TRANSFER')->firstOrFail();
        $this->assertSame($yesterday, $movement->movement_date->format('Y-m-d'));
    }

    public function test_a_future_date_is_rejected(): void
    {
        $target = $this->existingBatch('Recría Futuro REG');

        $this->register($this->animals(['REG-F1']), ['target_batch_id' => $target->id], now()->addDay()->toDateString())
            ->assertStatus(422)
            ->assertJsonValidationErrors('movement_date');
    }

    public function test_registering_to_a_new_batch_creates_it(): void
    {
        $response = $this->register($this->animals(['REG-N1']), [
            'new_batch_name' => 'Recría Nueva REG',
            'new_batch_type_id' => $this->operationalTypeId,
            'is_confined' => false,
        ]);

        $response->assertStatus(201);
        $this->assertSame('EXECUTED', $response->json('order.status'));
        $this->assertSame(1, Batch::where('name', 'Recría Nueva REG')->count());
    }

    public function test_a_new_batch_without_its_management_system_is_rejected(): void
    {
        $this->register($this->animals(['REG-N2']), [
            'new_batch_name' => 'Recría Incompleta REG',
            'new_batch_type_id' => $this->operationalTypeId,
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'NEW_BATCH_INCOMPLETE');

        $this->assertSame(0, TransferOrder::where('source_batch_id', $this->source->id)->count());
    }

    public function test_per_animal_with_an_animal_without_destination_is_rejected(): void
    {
        $animals = $this->animals(['REG-P1', 'REG-P2']);
        $target = $this->existingBatch('Recría Por Animal REG');

        $this->apiAs('POST', '/transfer-orders/register', [
            ...$this->header(now()->toDateString(), 'per_animal'),
            'destinations' => [['key' => 'dest-1', 'label' => $target->name, 'target_batch_id' => $target->id]],
            'animals' => [
                ['caravan_id' => $animals[0]->id, 'destination_key' => 'dest-1'],
                ['caravan_id' => $animals[1]->id, 'destination_key' => null],
            ],
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'DESTINATION_MISSING');
    }

    public function test_an_animal_committed_to_an_issued_order_is_rejected_and_nothing_is_left_behind(): void
    {
        $animals = $this->animals(['REG-C1']);
        $target = $this->existingBatch('Recría Comprometida REG');

        $this->apiAs('POST', '/transfer-orders', [
            ...$this->header(now()->toDateString(), 'single'),
            'issue' => true,
            'destinations' => [['key' => 'dest-single', 'label' => '', 'target_batch_id' => $target->id]],
            'animals' => [['caravan_id' => $animals[0]->id, 'destination_key' => 'dest-single']],
        ])->assertStatus(201);

        $this->register($animals, ['target_batch_id' => $target->id])
            ->assertStatus(422)
            ->assertJsonPath('code', 'ANIMAL_IN_OPEN_ORDER');

        $this->assertSame(1, TransferOrder::where('source_batch_id', $this->source->id)->count());
        $this->assertSame($this->source->id, (int) $animals[0]->fresh()->batch_id);
    }

    public function test_a_draft_of_the_batch_does_not_block_the_registration(): void
    {
        $inDraft = $this->animals(['REG-B1']);
        $moved = $this->animals(['REG-B2']);
        $target = $this->existingBatch('Recría Con Borrador REG');

        $this->apiAs('POST', '/transfer-orders', [
            ...$this->header(now()->toDateString(), 'single'),
            'destinations' => [['key' => 'dest-single', 'label' => '', 'target_batch_id' => $target->id]],
            'animals' => [['caravan_id' => $inDraft[0]->id, 'destination_key' => 'dest-single']],
        ])->assertStatus(201)->assertJsonPath('status', 'DRAFT');

        $this->register($moved, ['target_batch_id' => $target->id])->assertStatus(201);
    }

    public function test_the_list_filters_by_kind(): void
    {
        $target = $this->existingBatch('Recría Listado REG');
        $registered = $this->register($this->animals(['REG-L1']), ['target_batch_id' => $target->id])->json('order.code');

        $batch = '&source_batch_id=' . $this->source->id;

        $this->assertSame(
            [$registered],
            array_column($this->apiAs('GET', '/transfer-orders?kind=REGISTERED' . $batch)->json(), 'code')
        );
        $this->assertSame([], $this->apiAs('GET', '/transfer-orders?kind=PLANNED' . $batch)->json());
    }

    public function test_a_backdated_movement_writes_the_weight_series_on_the_declared_day(): void
    {
        $yesterday = now()->subDay()->toDateString();
        $animals = $this->animals(['REG-W1', 'REG-W2']);
        $target = $this->existingBatch('Recría Curva REG');

        // The source batch already has a point today, after the day the movement happened.
        BatchWeight::create([
            'batch_id' => $this->source->id,
            'activity_id' => $this->source->activity_id,
            'weight' => null,
            'total_weight' => 0,
            'caravans_count' => 2,
            'weighed_count' => 0,
            'type' => 'CONTROL',
            'weighing_date' => now()->toDateString(),
        ]);

        $this->register($animals, ['target_batch_id' => $target->id], $yesterday)->assertStatus(201);

        $this->assertTrue(
            BatchWeight::where('batch_id', $target->id)->whereDate('weighing_date', $yesterday)->exists(),
            'El destino recibe su punto en la fecha del hecho'
        );
        $this->assertTrue(
            BatchWeight::where('batch_id', $this->source->id)->whereDate('weighing_date', $yesterday)->exists(),
            'El origen recibe su punto en la fecha del hecho aunque ya tenga uno posterior'
        );
    }

    // ------------------------------------------------------------------ field data

    public function test_a_weight_measured_at_the_chute_is_a_weighing_on_the_declared_day(): void
    {
        $yesterday = now()->subDay()->toDateString();
        $animals = $this->animals(['REG-FW1']);
        $target = $this->existingBatch('Recría Peso REG');

        $this->registerWithField($animals[0], ['target_batch_id' => $target->id], ['current_weight' => 412.5], $yesterday)
            ->assertStatus(201);

        $weight = CaravanWeight::where('caravan_id', $animals[0]->id)->where('current', true)->firstOrFail();
        $this->assertEquals(412.5, (float) $weight->weight);
        $this->assertSame($yesterday, $weight->weighing_date->format('Y-m-d'));
    }

    public function test_a_late_weighing_does_not_displace_a_later_current_one(): void
    {
        $animals = $this->animals(['REG-FW2']);
        $target = $this->existingBatch('Recría Peso Tardío REG');

        CaravanWeight::create([
            'caravan_id' => $animals[0]->id,
            'weight' => 300,
            'current' => true,
            'weighing_date' => now()->toDateString(),
        ]);

        $this->registerWithField($animals[0], ['target_batch_id' => $target->id], ['current_weight' => 280], now()->subDays(3)->toDateString())
            ->assertStatus(201);

        $this->assertEquals(300.0, (float) CaravanWeight::where('caravan_id', $animals[0]->id)->where('current', true)->value('weight'));
        $this->assertSame(2, CaravanWeight::where('caravan_id', $animals[0]->id)->count(), 'La pesada tardía queda en la historia');
    }

    public function test_teeth_move_up_and_a_lower_reading_only_warns(): void
    {
        [$up, $down] = $this->animals(['REG-T1', 'REG-T2']);
        $down->update(['teeth' => 4]);
        $target = $this->existingBatch('Recría Dientes REG');

        $response = $this->apiAs('POST', '/transfer-orders/register', [
            ...$this->header(now()->toDateString(), 'single'),
            'destinations' => [['key' => 'dest-single', 'label' => '', 'target_batch_id' => $target->id]],
            'animals' => [
                ['caravan_id' => $up->id, 'destination_key' => 'dest-single', 'teeth' => '2D'],
                ['caravan_id' => $down->id, 'destination_key' => 'dest-single', 'teeth' => '2D'],
            ],
        ]);

        $response->assertStatus(201);
        $this->assertSame(2, (int) $up->fresh()->teeth);
        $this->assertSame(4, (int) $down->fresh()->teeth);
        $this->assertContains('TEETH_REGRESSION', array_column($response->json('warnings'), 'code'));
    }

    public function test_a_new_category_reclassifies_clears_the_subcategory_and_is_noted(): void
    {
        $animals = $this->animals(['REG-CAT1']);
        $novillito = $this->categoryId('NOVILLITO');
        $subcategory = AnimalSubcategory::create([
            'category_id' => $novillito,
            'code' => 'NOVILLITO_REG_TEST',
            'name' => 'Novillito de prueba REG',
        ])->id;
        $animals[0]->update(['category_id' => $novillito, 'subcategory_id' => $subcategory]);
        $this->assertSame($subcategory, (int) $animals[0]->fresh()->subcategory_id, 'Precondición: el animal tiene subcategoría');
        $target = $this->existingBatch('Recría Categoría REG');

        $this->registerWithField($animals[0], ['target_batch_id' => $target->id], ['category_id' => $this->categoryId('NOVILLO')])
            ->assertStatus(201);

        $animal = $animals[0]->fresh();
        $this->assertSame($this->categoryId('NOVILLO'), (int) $animal->category_id);
        $this->assertNull($animal->subcategory_id);
        $this->assertStringContainsString(
            // The note says what the animal was, subcategory included, in the C/S form of the sheet.
            'Categoría: Novillito / prueba REG → Novillo.',
            (string) CaravanMovement::where('caravan_id', $animal->id)->where('type', 'TRANSFER')->value('observations')
        );
    }

    public function test_a_category_of_the_other_sex_is_a_row_error_and_nothing_is_left_behind(): void
    {
        $animals = $this->animals(['REG-CAT2']);
        $target = $this->existingBatch('Recría Categoría Mal REG');

        $response = $this->registerWithField($animals[0], ['target_batch_id' => $target->id], ['category_id' => $this->categoryId('VAQUILLONA')]);

        $response->assertStatus(422);
        $this->assertSame('REG-CAT2', $response->json('row_errors.0.caravana'));
        $this->assertSame('CATEGORY_SEX_MISMATCH', $response->json('row_errors.0.errors.0.code'));
        $this->assertSame(0, TransferOrder::where('source_batch_id', $this->source->id)->count());
        $this->assertSame($this->source->id, (int) $animals[0]->fresh()->batch_id);
    }

    public function test_a_subcategory_reclassifies_with_it_and_is_noted(): void
    {
        $animals = $this->animals(['REG-SUB1']);
        $animals[0]->update(['sex' => 'H', 'category_id' => $this->categoryId('TERNERO')]);
        $vaquillona = $this->categoryId('VAQUILLONA');
        $reposicion = (int) AnimalSubcategory::where('category_id', $vaquillona)->where('code', 'REPOSICION')->value('id');
        $target = $this->existingBatch('Recría Subcategoría REG');

        $this->registerWithField(
            $animals[0],
            ['target_batch_id' => $target->id],
            ['category_id' => $vaquillona, 'subcategory_id' => $reposicion]
        )->assertStatus(201);

        $animal = $animals[0]->fresh();
        $this->assertSame($vaquillona, (int) $animal->category_id);
        $this->assertSame($reposicion, (int) $animal->subcategory_id);
        $this->assertStringContainsString(
            'Categoría: Ternero → Vaquillona / Reposición.',
            (string) CaravanMovement::where('caravan_id', $animal->id)->where('type', 'TRANSFER')->value('observations')
        );
    }

    public function test_a_subcategory_of_another_category_is_a_row_error(): void
    {
        $animals = $this->animals(['REG-SUB2']);
        $animals[0]->update(['sex' => 'H']);
        $plantel = (int) AnimalSubcategory::where('category_id', $this->categoryId('VACA'))->where('code', 'PLANTEL')->value('id');
        $target = $this->existingBatch('Recría Subcategoría Mal REG');

        $response = $this->registerWithField(
            $animals[0],
            ['target_batch_id' => $target->id],
            ['category_id' => $this->categoryId('VAQUILLONA'), 'subcategory_id' => $plantel]
        );

        $response->assertStatus(422);
        $this->assertSame('CATEGORY_NOT_FOUND', $response->json('row_errors.0.errors.0.code'));
        $this->assertSame($this->source->id, (int) $animals[0]->fresh()->batch_id);
    }

    public function test_a_weight_of_zero_is_rejected(): void
    {
        $target = $this->existingBatch('Recría Peso Cero REG');

        $this->registerWithField($this->animals(['REG-Z0'])[0], ['target_batch_id' => $target->id], ['current_weight' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors('animals.0.current_weight');
    }

    public function test_observations_reach_the_movement_of_that_animal(): void
    {
        $animals = $this->animals(['REG-OBS']);
        $target = $this->existingBatch('Recría Obs REG');

        $this->registerWithField($animals[0], ['target_batch_id' => $target->id], ['observations' => 'Rengo mano derecha'])
            ->assertStatus(201);

        $this->assertStringContainsString(
            'Rengo mano derecha',
            (string) CaravanMovement::where('caravan_id', $animals[0]->id)->where('type', 'TRANSFER')->value('observations')
        );
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @param list<Caravan> $animals
     * @param array<string, mixed> $destination
     */
    private function register(array $animals, array $destination, ?string $date = null)
    {
        return $this->apiAs('POST', '/transfer-orders/register', [
            ...$this->header($date ?? now()->toDateString(), 'single'),
            'destinations' => [['key' => 'dest-single', 'label' => '', ...$destination]],
            'animals' => array_map(fn (Caravan $c) => ['caravan_id' => $c->id, 'destination_key' => 'dest-single'], $animals),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function header(string $date, string $mode): array
    {
        return [
            'source_batch_id' => $this->source->id,
            'destination_activity_id' => $this->activityId('RECRIA'),
            'destination_mode' => $mode,
            'movement_date' => $date,
        ];
    }

    /**
     * @param list<string> $tags
     * @return list<Caravan>
     */
    private function animals(array $tags): array
    {
        return array_map(fn (string $tag) => Caravan::create([
            'company_id' => $this->company->id,
            'batch_id' => $this->source->id,
            'identification' => $tag,
            'sex' => 'M',
            'teeth' => 0,
        ]), $tags);
    }

    private function existingBatch(string $name): Batch
    {
        return Batch::create([
            'company_id' => $this->company->id,
            'name' => $name,
            'activity_id' => $this->activityId('RECRIA'),
            'batch_type_id' => $this->operationalTypeId,
            'is_confined' => false,
            'is_active' => true,
        ]);
    }

    /**
     * @param array<string, mixed> $destination
     * @param array<string, mixed> $field
     */
    private function registerWithField(Caravan $animal, array $destination, array $field, ?string $date = null)
    {
        return $this->apiAs('POST', '/transfer-orders/register', [
            ...$this->header($date ?? now()->toDateString(), 'single'),
            'destinations' => [['key' => 'dest-single', 'label' => '', ...$destination]],
            'animals' => [['caravan_id' => $animal->id, 'destination_key' => 'dest-single', ...$field]],
        ]);
    }

    private function categoryId(string $code): int
    {
        return (int) AnimalCategory::where('code', $code)->value('id');
    }

    private function activityId(string $code): int
    {
        return (int) Activity::withoutGlobalScopes()->where('code', $code)->value('id');
    }
}
