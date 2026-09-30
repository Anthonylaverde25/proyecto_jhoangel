<?php

declare(strict_types=1);

namespace Tests\Feature\WeaningOrders;

use App\Models\Batch;
use App\Models\Caravan;
use App\Models\CaravanMovement;
use App\Models\CaravanWeight;
use App\Models\WeaningOrder;

/**
 * "Registrar destete": the calves were already weaned, the system learns it afterwards. The order
 * is born executed and dated on the day it happened.
 */
class RegisterWeaningTest extends WeaningOrderTestCase
{
    public function test_a_registered_weaning_is_born_executed_on_the_declared_day(): void
    {
        $yesterday = now()->subDay()->toDateString();
        $weighed = $this->nursingCalf('WO-R1', 'M');
        $notWeighed = $this->nursingCalf('WO-R2', 'H', $this->breedingB);

        $response = $this->register([$weighed, $notWeighed], [
            'weaning_date' => $yesterday,
            'weaning_type' => 'EARLY',
            'animals' => [
                ['caravan_id' => $weighed->id, 'destination_key' => 'd1', 'weight' => 96.5],
                ['caravan_id' => $notWeighed->id, 'destination_key' => 'd1'],
            ],
        ]);

        $response->assertStatus(201);
        $this->assertSame('EXECUTED', $response->json('order.status'));
        $this->assertSame('REGISTERED', $response->json('order.kind'));
        $this->assertSame('Precoz', $response->json('order.weaning_type_label'));
        $this->assertSame(2, $response->json('order.weaned_head_count'));

        $movement = CaravanMovement::where('caravan_id', $weighed->id)->where('type', 'WEANING')->firstOrFail();
        $this->assertSame($yesterday, $movement->movement_date->format('Y-m-d'));
        $this->assertStringContainsString('registrado después del hecho', (string) $movement->observations);

        // The weight is optional, as on the sheet.
        $this->assertEquals(96.5, (float) CaravanWeight::where('caravan_id', $weighed->id)->where('current', true)->value('weight'));
        $this->assertSame(0, CaravanWeight::where('caravan_id', $notWeighed->id)->count());
        $this->assertFalse($this->isNursing($notWeighed));
    }

    public function test_one_calf_can_be_registered_with_a_declared_category(): void
    {
        $female = $this->nursingCalf('WO-R3', 'H');

        $this->register([$female], [
            'category_mode' => 'DECLARED',
            'animals' => [[
                'caravan_id' => $female->id,
                'destination_key' => 'd1',
                'target_category_id' => $this->categoryId('VAQUILLONA'),
                'target_subcategory_id' => $this->subcategoryId('VAQUILLONA', 'REPOSICION'),
            ]],
        ])->assertStatus(201);

        $this->assertSame($this->categoryId('VAQUILLONA'), (int) $female->fresh()->category_id);
    }

    public function test_the_chute_already_happened_so_nothing_is_left_undecided(): void
    {
        $calf = $this->nursingCalf('WO-R4', 'M');
        $before = WeaningOrder::count();

        $this->register([$calf], ['category_mode' => 'AT_CHUTE'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'CATEGORY_MODE_NOT_ALLOWED');

        $this->register([$calf], [
            'destination_mode' => 'per_animal',
            'animals' => [['caravan_id' => $calf->id, 'destination_key' => null]],
        ])->assertStatus(422)->assertJsonPath('code', 'DESTINATION_MISSING');

        $this->register([$calf], [
            'destinations' => [['key' => 'd1', 'label' => '', 'new_batch_name' => 'Destete Incompleto WO']],
        ])->assertStatus(422)->assertJsonPath('code', 'NEW_BATCH_INCOMPLETE');

        $this->register([$calf], ['weaning_date' => now()->addDay()->toDateString()])
            ->assertStatus(422)
            ->assertJsonValidationErrors('weaning_date');

        $this->assertSame($before, WeaningOrder::count());
        $this->assertTrue($this->isNursing($calf));
    }

    public function test_a_registration_into_a_new_batch_creates_it_with_its_management_system(): void
    {
        $calf = $this->nursingCalf('WO-R5', 'M');

        $this->register([$calf], [
            'destinations' => [['key' => 'd1', 'label' => '', 'new_batch_name' => 'Destete Registrado WO', 'is_confined' => true]],
        ])->assertStatus(201);

        $batch = Batch::where('name', 'Destete Registrado WO')->firstOrFail();
        $this->assertTrue((bool) $batch->is_confined);
        $this->assertSame($batch->id, (int) $calf->fresh()->batch_id);
    }

    public function test_a_calf_committed_to_an_issued_order_is_refused_and_nothing_is_left_behind(): void
    {
        $calf = $this->nursingCalf('WO-R6', 'M');
        $this->emitSingle([$calf])->assertStatus(201);
        $before = WeaningOrder::count();

        $this->register([$calf])->assertStatus(422)->assertJsonPath('code', 'ANIMAL_IN_OPEN_ORDER');

        $this->assertSame($before, WeaningOrder::count());
        $this->assertTrue($this->isNursing($calf));
    }

    public function test_a_failed_weaning_leaves_no_order_behind(): void
    {
        $calf = $this->nursingCalf('WO-R7', 'M', null, now()->toDateString());
        $before = WeaningOrder::count();

        // Weaned before it was born: the weaning fails, and the order with it.
        $this->register([$calf], ['weaning_date' => now()->subDays(10)->toDateString()])
            ->assertStatus(422)
            ->assertJsonPath('row_errors.0.errors.0.code', 'WEANING_BEFORE_BIRTH');

        $this->assertSame($before, WeaningOrder::count());
    }

    /**
     * @param Caravan[] $calves
     * @param array<string, mixed> $overrides
     */
    private function register(array $calves, array $overrides = [])
    {
        return $this->apiAs('POST', '/weaning-orders/register', [
            'destination_mode' => 'single',
            'weaning_date' => now()->toDateString(),
            'destinations' => [['key' => 'd1', 'label' => '', 'target_batch_id' => $this->weaningBatch->id]],
            'animals' => array_map(fn (Caravan $c) => ['caravan_id' => $c->id, 'destination_key' => 'd1'], $calves),
            ...$overrides,
        ]);
    }
}
