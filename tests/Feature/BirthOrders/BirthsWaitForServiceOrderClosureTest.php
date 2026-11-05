<?php

declare(strict_types=1);

namespace Tests\Feature\BirthOrders;

use App\Models\Batch;
use App\Models\BirthOrder;
use App\Models\Caravan;
use App\Models\CaravanGestation;
use App\Models\ServiceOrder;
use Database\Seeders\BirthOrderTestSeeder;

/**
 * The calving season starts after the service: a pregnancy of a service order that is not closed
 * yet cannot enter a birth order or be registered, by any path. The rule follows the pregnancy, not
 * the batch: a cow pregnant of a closed order calves even if her batch runs another service today.
 */
class BirthsWaitForServiceOrderClosureTest extends BirthOrderTestCase
{
    private int $orderSequence = 0;

    public function test_every_status_of_an_order_not_closed_holds_its_pregnancies(): void
    {
        foreach (['APPROVED', 'BULLS_WITHDRAWN', 'SERVICE_ENDED'] as $index => $status) {
            $cow = $this->pregnantOf("BW-S{$index}", $status);

            $this->emit([$cow])->assertStatus(422)->assertJsonPath('code', 'SERVICE_ORDER_NOT_CLOSED');
        }
    }

    public function test_a_draft_written_before_cannot_be_issued_while_the_order_is_open(): void
    {
        $cow = $this->pregnantFemale('BW-D1');
        $orderId = (int) $this->emit([$cow], ['issue' => false])->assertStatus(201)->json('id');
        $this->linkToServiceOrder($cow, 'APPROVED');

        $this->apiAs('POST', "/birth-orders/{$orderId}/issue")->assertStatus(422)->assertJsonPath('code', 'SERVICE_ORDER_NOT_CLOSED');
    }

    public function test_registering_is_refused_and_leaves_no_order(): void
    {
        $cow = $this->pregnantOf('BW-R1', 'BULLS_WITHDRAWN');
        $before = BirthOrder::count();

        $this->apiAs('POST', '/birth-orders/register', ['animals' => [$this->liveAnimal($cow, 'BW-R1-C')]])
            ->assertStatus(422)
            ->assertJsonPath('code', 'SERVICE_ORDER_NOT_CLOSED');

        $this->assertSame($before, BirthOrder::count());
        $this->assertNull(Caravan::where('identification', 'BW-R1-C')->first());
    }

    public function test_a_sheet_reports_it_on_the_row_and_an_unmarked_row_is_not_a_problem(): void
    {
        $marked = $this->pregnantOf('BW-P1', 'BULLS_WITHDRAWN');
        $unmarked = $this->pregnantOf('BW-P2', 'BULLS_WITHDRAWN');
        $free = $this->pregnantFemale('BW-P3');

        $this->scan(null, [
            $this->liveRow($marked, 'BW-P1-C'),
            ['caravana_madre' => $unmarked->identification, 'resultado' => ''],
            $this->liveRow($free, 'BW-P3-C'),
        ])
            ->assertStatus(422)
            ->assertJsonCount(1, 'row_errors')
            ->assertJsonPath('row_errors.0.row_index', 0)
            ->assertJsonPath('row_errors.0.errors.0.code', 'SERVICE_ORDER_NOT_CLOSED')
            ->assertJsonPath('row_errors.0.errors.0.field', 'caravana_madre');

        $this->assertNull(Caravan::where('identification', 'BW-P3-C')->first());
    }

    public function test_an_order_issued_before_cannot_register_until_the_service_closes(): void
    {
        $cow = $this->pregnantFemale('BW-E1');
        $orderId = (int) $this->emit([$cow])->assertStatus(201)->json('id');
        $service = $this->linkToServiceOrder($cow, 'BULLS_WITHDRAWN');
        $animal = $this->liveAnimal($cow, 'BW-E1-C');

        $this->apiAs('POST', "/birth-orders/{$orderId}/execute", ['animals' => [$animal]])
            ->assertStatus(422)
            ->assertJsonPath('row_errors.0.errors.0.code', 'SERVICE_ORDER_NOT_CLOSED');

        $service->update(['status' => 'SUCCESS']);

        $this->apiAs('POST', "/birth-orders/{$orderId}/execute", ['animals' => [$animal]])->assertStatus(201);
        $this->assertNotNull(Caravan::where('identification', 'BW-E1-C')->first());
    }

    public function test_the_old_birth_route_follows_the_same_rule(): void
    {
        $cow = $this->pregnantOf('BW-L1', 'APPROVED');

        $this->apiAs('POST', '/caravans/bulk-birth', ['births' => [[
            'calf_identification' => 'BW-L1-C',
            'calf_sex' => 'M',
            'calf_category' => 'ternero',
            'calf_teeth' => 0,
            'birth_date' => now()->toDateString(),
            'batch_id' => $this->breedingA->id,
            'mother_id' => $cow->id,
        ]]])->assertStatus(422);

        $this->assertNull(Caravan::where('identification', 'BW-L1-C')->first());
    }

    public function test_once_the_order_is_closed_the_calving_goes_through(): void
    {
        $cow = $this->pregnantOf('BW-C1', 'SUCCESS');

        $this->apiAs('POST', '/birth-orders/register', ['animals' => [$this->liveAnimal($cow, 'BW-C1-C')]])->assertStatus(201);
    }

    public function test_the_rule_follows_the_pregnancy_and_not_the_batch(): void
    {
        // Pregnant of a closed order, in a batch that runs another service today.
        $ofClosed = $this->pregnantOf('BW-B1', 'SUCCESS');
        $this->serviceOrder('APPROVED');
        // Bought pregnant: no service order behind her pregnancy.
        $bought = $this->pregnantFemale('BW-B2');

        $this->emit([$ofClosed, $bought])->assertStatus(201);
    }

    public function test_the_screens_learn_who_is_held_and_by_which_order(): void
    {
        $held = $this->pregnantOf('BW-L2', 'BULLS_WITHDRAWN');
        $free = $this->pregnantOf('BW-L3', 'SUCCESS');
        $order = CaravanGestation::where('caravan_id', $held->id)->value('service_order_id');

        $rows = collect($this->apiAs('GET', '/birth-orders/mothers-in-open-service')->assertOk()->json())->keyBy('caravan_id');

        $this->assertSame((int) $order, $rows[$held->id]['order_id']);
        $this->assertSame(ServiceOrder::find($order)->code, $rows[$held->id]['code']);
        $this->assertFalse($rows->has($free->id));
    }

    public function test_the_sheet_seeder_leaves_the_repair_case_of_the_simulation(): void
    {
        $this->seed(BirthOrderTestSeeder::class);
        $cow = Caravan::where('identification', 'PAR-V-45')->firstOrFail();

        $this->scan(null, [$this->liveRow($cow, 'PAR-C-45')])
            ->assertStatus(422)
            ->assertJsonPath('row_errors.0.errors.0.code', 'SERVICE_ORDER_NOT_CLOSED');

        ServiceOrder::where('code', BirthOrderTestSeeder::OPEN_SERVICE_CODE)->update(['status' => 'SUCCESS']);

        $this->scan(null, [$this->liveRow($cow, 'PAR-C-45')])->assertStatus(201);
    }

    private function pregnantOf(string $tag, string $orderStatus): Caravan
    {
        $cow = $this->pregnantFemale($tag);
        $this->linkToServiceOrder($cow, $orderStatus);

        return $cow;
    }

    private function linkToServiceOrder(Caravan $cow, string $status): ServiceOrder
    {
        $order = $this->serviceOrder($status);
        CaravanGestation::where('caravan_id', $cow->id)->where('is_current', true)->update(['service_order_id' => $order->id]);

        return $order;
    }

    private function serviceOrder(string $status, ?Batch $batch = null): ServiceOrder
    {
        $this->orderSequence++;

        return ServiceOrder::create([
            'company_id' => $this->company->id,
            'batch_id' => ($batch ?? $this->breedingB)->id,
            'code' => "SO-BW-{$this->orderSequence}",
            'status' => $status,
            'service_type' => 'single',
            'planned_start_date' => now()->subMonths(10)->toDateString(),
        ]);
    }

    /** @return array<string, mixed> */
    private function liveAnimal(Caravan $cow, string $calfTag): array
    {
        return [
            'caravan_id' => $cow->id,
            'outcome' => 'LIVE',
            'calf_identification' => $calfTag,
            'calf_sex' => 'M',
            'calf_weight' => 32,
            'birth_date' => now()->toDateString(),
        ];
    }
}
