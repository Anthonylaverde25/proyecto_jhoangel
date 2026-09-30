<?php

declare(strict_types=1);

namespace Tests\Feature\TransferOrders;

use App\Models\Activity;
use App\Models\AnimalCategory;
use App\Models\AnimalSubcategory;
use App\Models\Batch;
use App\Models\BatchType;
use App\Models\Caravan;
use App\Models\CaravanMovement;
use App\Models\CaravanWeight;
use App\Models\TransferOrder;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Veterinary\VeterinaryTestCase;

/**
 * "Ejecutar orden" declares when the movement happened and the C/S of each animal, and every way
 * a movement is loaded warns about a pregnant female going to cull and a weight outside the range
 * of the new category.
 */
class TransferOrderExecutionDeclarationTest extends VeterinaryTestCase
{
    private Batch $source;
    private Batch $recria;
    private Batch $invernada;

    protected function setUp(): void
    {
        parent::setUp();

        $operational = (int) BatchType::withoutGlobalScopes()->where('code', 'OPERATIONAL')->value('id');
        $batch = fn (string $name, string $activity) => Batch::create([
            'company_id' => $this->company->id,
            'name' => $name,
            'activity_id' => $this->activityId($activity),
            'batch_type_id' => $operational,
            'is_confined' => false,
            'is_active' => true,
        ]);

        $this->source = $batch('Cría Origen EXE', 'CRIA');
        $this->recria = $batch('Recría Destino EXE', 'RECRIA');
        $this->invernada = $batch('Invernada Destino EXE', 'INVERNADA');
    }

    // ------------------------------------------------------------------ date of the movement

    public function test_the_movement_takes_the_date_given_on_execution(): void
    {
        $animal = $this->animal('EXE-D1', 'M', 'TERNERO');
        $orderId = $this->issue([$animal], $this->recria);
        TransferOrder::whereKey($orderId)->update(['emitted_at' => now()->subDays(5)]);

        $this->execute($orderId, ['movement_date' => now()->subDays(2)->toDateString()])->assertStatus(201);

        $this->assertSame(
            now()->subDays(2)->toDateString(),
            substr((string) CaravanMovement::where('caravan_id', $animal->id)->value('movement_date'), 0, 10)
        );
    }

    public function test_without_a_date_it_is_today_as_before(): void
    {
        $animal = $this->animal('EXE-D2', 'M', 'TERNERO');

        $this->execute($this->issue([$animal], $this->recria))->assertStatus(201);

        $this->assertSame(now()->toDateString(), substr((string) CaravanMovement::where('caravan_id', $animal->id)->value('movement_date'), 0, 10));
    }

    public function test_a_future_date_is_rejected(): void
    {
        $orderId = $this->issue([$this->animal('EXE-D3', 'M', 'TERNERO')], $this->recria);

        $this->execute($orderId, ['movement_date' => now()->addDay()->toDateString()])
            ->assertStatus(422)
            ->assertJsonValidationErrors('movement_date');
    }

    public function test_a_date_before_the_order_was_issued_is_rejected_on_execution_and_on_the_sheet(): void
    {
        $animal = $this->animal('EXE-D4', 'M', 'TERNERO');
        $orderId = $this->issue([$animal], $this->recria);
        $yesterday = now()->subDay()->toDateString();

        $this->execute($orderId, ['movement_date' => $yesterday])
            ->assertStatus(422)
            ->assertJsonPath('header_errors.0.code', 'MOVEMENT_BEFORE_ORDER');

        $this->apiAs('POST', '/work-templates/cact-01/process', [
            'source_batch_id' => $this->source->id,
            'fecha_movimiento' => $yesterday,
            'actividad_destino_id' => $this->activityId('RECRIA'),
            'transfer_order_id' => $orderId,
            'destinations' => [['key' => 'D', 'target_batch_id' => $this->recria->id, 'new_batch' => null]],
            'rows' => [['caravana' => 'EXE-D4', 'destination_key' => 'D']],
        ])->assertStatus(422)->assertJsonPath('header_errors.0.code', 'MOVEMENT_BEFORE_ORDER');

        $this->assertSame($this->source->id, (int) $animal->fresh()->batch_id);
    }

    public function test_registering_after_the_fact_is_not_bound_by_the_issue_date(): void
    {
        // Its order is born with the movement, so a past date is the whole point.
        $this->apiAs('POST', '/transfer-orders/register', [
            ...$this->header('single'),
            'movement_date' => now()->subDays(3)->toDateString(),
            'destinations' => [['key' => 'd', 'label' => '', 'target_batch_id' => $this->recria->id]],
            'animals' => [['caravan_id' => $this->animal('EXE-D5', 'M', 'TERNERO')->id, 'destination_key' => 'd']],
        ])->assertStatus(201);
    }

    // ------------------------------------------------------------------ C/S declared on execution

    public function test_an_at_chute_order_executed_from_the_screen_applies_the_declared_cs(): void
    {
        $male = $this->animal('EXE-C1', 'M', 'TERNERO');
        $female = $this->animal('EXE-C2', 'H', 'TERNERO');
        $keeps = $this->animal('EXE-C3', 'M', 'TERNERO');
        $orderId = $this->issue([$male, $female, $keeps], $this->recria, 'AT_CHUTE');

        $this->execute($orderId, ['animals' => [
            ['caravan_id' => $male->id, 'category_id' => $this->categoryId('NOVILLITO')],
            ['caravan_id' => $female->id, 'category_id' => $this->categoryId('VAQUILLONA'), 'subcategory_id' => $this->subcategoryId('VAQUILLONA', 'REPOSICION')],
        ]])->assertStatus(201);

        $this->assertSame($this->categoryId('NOVILLITO'), (int) $male->fresh()->category_id);
        $this->assertSame($this->subcategoryId('VAQUILLONA', 'REPOSICION'), (int) $female->fresh()->subcategory_id);
        $this->assertSame($this->categoryId('TERNERO'), (int) $keeps->fresh()->category_id);
    }

    public function test_choosing_another_cs_than_the_declared_one_warns(): void
    {
        $animal = $this->animal('EXE-C4', 'M', 'TERNERO');
        $orderId = $this->issue([$animal], $this->recria, 'DECLARED', [$animal->id => $this->categoryId('NOVILLITO')]);

        $response = $this->execute($orderId, ['animals' => [['caravan_id' => $animal->id, 'category_id' => $this->categoryId('TORITO')]]]);

        $response->assertStatus(201);
        $this->assertContains('CATEGORY_DIFFERS_FROM_ORDER', $this->warningCodes($response));
        $this->assertSame($this->categoryId('TORITO'), (int) $animal->fresh()->category_id);
    }

    // ------------------------------------------------------------------ pregnancy

    public function test_a_pregnant_cow_to_finishing_warns(): void
    {
        $cow = $this->animal('EXE-P1', 'H', 'VACA');
        $this->pregnant($cow);

        $response = $this->execute($this->issue([$cow], $this->invernada));

        $response->assertStatus(201);
        $this->assertContains('PREGNANT_TO_CULL', $this->warningCodes($response));
    }

    public function test_a_pregnant_cow_declared_cull_warns_even_within_the_breeding_stages(): void
    {
        $cow = $this->animal('EXE-P2', 'H', 'VACA');
        $this->pregnant($cow);

        $response = $this->apiAs('POST', '/transfer-orders/register', [
            ...$this->header('single'),
            'movement_date' => now()->toDateString(),
            'destinations' => [['key' => 'd', 'label' => '', 'target_batch_id' => $this->recria->id]],
            'animals' => [[
                'caravan_id' => $cow->id,
                'destination_key' => 'd',
                'category_id' => $this->categoryId('VACA'),
                'subcategory_id' => $this->subcategoryId('VACA', 'DESCARTE_CUT'),
            ]],
        ]);

        $response->assertStatus(201);
        $this->assertContains('PREGNANT_TO_CULL', array_column($response->json('warnings'), 'code'));
    }

    public function test_a_pregnant_heifer_going_to_breeding_does_not_warn(): void
    {
        $heifer = $this->animal('EXE-P3', 'H', 'VAQUILLONA');
        $this->pregnant($heifer);

        $response = $this->execute($this->issue([$heifer], $this->recria));

        $response->assertStatus(201);
        $this->assertNotContains('PREGNANT_TO_CULL', $this->warningCodes($response));
    }

    // ------------------------------------------------------------------ weight

    public function test_a_new_category_outside_its_weight_range_warns(): void
    {
        $animal = $this->animal('EXE-W1', 'M', 'NOVILLITO', 250.0);

        $response = $this->apiAs('POST', '/transfer-orders/register', [
            ...$this->header('single', (int) $this->invernada->activity_id),
            'movement_date' => now()->toDateString(),
            'destinations' => [['key' => 'd', 'label' => '', 'target_batch_id' => $this->invernada->id]],
            'animals' => [['caravan_id' => $animal->id, 'destination_key' => 'd', 'category_id' => $this->categoryId('NOVILLO')]],
        ]);

        $response->assertStatus(201);
        $this->assertContains('CATEGORY_WEIGHT_OUT_OF_RANGE', array_column($response->json('warnings'), 'code'));
    }

    public function test_the_weight_of_the_day_is_the_one_compared(): void
    {
        $animal = $this->animal('EXE-W2', 'M', 'NOVILLITO', 250.0);

        $response = $this->apiAs('POST', '/transfer-orders/register', [
            ...$this->header('single', (int) $this->invernada->activity_id),
            'movement_date' => now()->toDateString(),
            'destinations' => [['key' => 'd', 'label' => '', 'target_batch_id' => $this->invernada->id]],
            'animals' => [[
                'caravan_id' => $animal->id,
                'destination_key' => 'd',
                'category_id' => $this->categoryId('NOVILLO'),
                'current_weight' => 420,
            ]],
        ]);

        $response->assertStatus(201);
        $this->assertNotContains('CATEGORY_WEIGHT_OUT_OF_RANGE', array_column($response->json('warnings'), 'code'));
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @param list<Caravan> $animals
     * @param array<int, int> $targets caravan id => category id, for a DECLARED order
     */
    private function issue(array $animals, Batch $target, ?string $mode = null, array $targets = []): int
    {
        $response = $this->apiAs('POST', '/transfer-orders', [
            ...$this->header('single', (int) $target->activity_id),
            'issue' => true,
            'movement_date' => now()->toDateString(),
            ...($mode !== null ? ['category_mode' => $mode] : []),
            'destinations' => [['key' => 'd', 'label' => '', 'target_batch_id' => $target->id]],
            'animals' => array_map(fn (Caravan $c) => [
                'caravan_id' => $c->id,
                'destination_key' => 'd',
                'target_category_id' => $targets[$c->id] ?? null,
            ], $animals),
        ]);

        $response->assertStatus(201);

        return (int) $response->json('id');
    }

    /**
     * @param array<string, mixed> $body
     */
    private function execute(int $orderId, array $body = [])
    {
        return $this->apiAs('POST', "/transfer-orders/{$orderId}/execute", $body);
    }

    /**
     * @return array<string, mixed>
     */
    private function header(string $mode, ?int $activityId = null): array
    {
        return [
            'source_batch_id' => $this->source->id,
            'destination_activity_id' => $activityId ?? (int) $this->recria->activity_id,
            'destination_mode' => $mode,
        ];
    }

    /**
     * @return list<string>
     */
    private function warningCodes($response): array
    {
        return array_column($response->json('data.warnings') ?? [], 'code');
    }

    private function animal(string $tag, string $sex, string $category, ?float $weight = null): Caravan
    {
        $caravan = Caravan::create([
            'company_id' => $this->company->id,
            'batch_id' => $this->source->id,
            'identification' => $tag,
            'sex' => $sex,
            'teeth' => 2,
            'category_id' => $this->categoryId($category),
        ]);

        if ($weight !== null) {
            CaravanWeight::create(['caravan_id' => $caravan->id, 'weight' => $weight, 'current' => true, 'weighing_date' => now()->subMonth()->toDateString()]);
        }

        return $caravan;
    }

    private function pregnant(Caravan $caravan): void
    {
        DB::table('caravan_gestations')->insert([
            'caravan_id' => $caravan->id,
            'start_date' => now()->subMonths(5)->toDateString(),
            'estimated_due_date' => now()->addMonths(4)->toDateString(),
            'is_current' => true,
            'gestation_stage' => 'head',
            'gestation_months' => 5.0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function categoryId(string $code): int
    {
        return (int) AnimalCategory::where('code', $code)->value('id');
    }

    private function subcategoryId(string $category, string $code): int
    {
        return (int) AnimalSubcategory::where('category_id', $this->categoryId($category))->where('code', $code)->value('id');
    }

    private function activityId(string $code): int
    {
        return (int) Activity::withoutGlobalScopes()->where('code', $code)->value('id');
    }
}
