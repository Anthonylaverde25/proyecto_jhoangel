<?php

declare(strict_types=1);

namespace Tests\Feature\ServiceOrders;

use App\Core\Enums\AnimalSex;
use App\Core\Enums\ServiceOrderMaleStatus;
use App\Core\Enums\ServiceOrderStatus;
use App\Models\Activity;
use App\Models\Batch;
use App\Models\Caravan;
use App\Models\CaravanMovement;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderFemale;
use App\Models\ServiceOrderMale;
use Tests\Feature\Veterinary\VeterinaryTestCase;

class CloseServiceOrderTest extends VeterinaryTestCase
{
    private Batch $serviceBatch;
    private Batch $restingBatch1;
    private Batch $restingBatch2;
    private Caravan $bull1;
    private Caravan $bull2;
    private Caravan $cow1;

    protected function setUp(): void
    {
        parent::setUp();

        $cria = Activity::updateOrCreate(['code' => 'CRIA'], ['name' => 'Cría']);

        $this->serviceBatch = Batch::create([
            'company_id'  => $this->company->id,
            'name'        => 'Lote de Servicio Primavera',
            'is_active'   => true,
            'activity_id' => $cria->id,
        ]);

        $this->restingBatch1 = Batch::create([
            'company_id'  => $this->company->id,
            'name'        => 'Torada Descanso Norte',
            'is_active'   => true,
            'activity_id' => $cria->id,
        ]);

        $this->restingBatch2 = Batch::create([
            'company_id'  => $this->company->id,
            'name'        => 'Potrero Enfermería',
            'is_active'   => true,
            'activity_id' => $cria->id,
        ]);

        $this->bull1 = Caravan::create([
            'company_id'     => $this->company->id,
            'batch_id'       => $this->serviceBatch->id,
            'identification' => 'TORO-001',
            'sex'            => AnimalSex::MALE->value,
        ]);

        $this->bull2 = Caravan::create([
            'company_id'     => $this->company->id,
            'batch_id'       => $this->serviceBatch->id,
            'identification' => 'TORO-002',
            'sex'            => AnimalSex::MALE->value,
        ]);

        $this->cow1 = Caravan::create([
            'company_id'     => $this->company->id,
            'batch_id'       => $this->serviceBatch->id,
            'identification' => 'VACA-001',
            'sex'            => AnimalSex::FEMALE->value,
        ]);
    }

    private function createApprovedOrder(): ServiceOrder
    {
        $order = ServiceOrder::create([
            'company_id'           => $this->company->id,
            'batch_id'             => $this->serviceBatch->id,
            'service_batch_id'     => $this->serviceBatch->id,
            'code'                 => 'SO-TEST-' . uniqid(),
            'status'               => ServiceOrderStatus::APPROVED->value,
            'planned_start_date'   => '2026-10-01',
            'planned_end_date'     => '2026-12-30',
            'actual_start_date'    => '2026-10-01',
            'requested_by_user_id' => $this->user->id,
            'approved_by_user_id'  => $this->user->id,
            'approved_at'          => now(),
            'executed_at'          => now(),
        ]);

        ServiceOrderFemale::create([
            'company_id'          => $this->company->id,
            'service_order_id'    => $order->id,
            'female_caravan_id'   => $this->cow1->id,
            'reproductive_status' => 'UNCHECKED',
        ]);

        ServiceOrderMale::create([
            'company_id'       => $this->company->id,
            'service_order_id' => $order->id,
            'male_caravan_id'  => $this->bull1->id,
            'status'           => ServiceOrderMaleStatus::ACTIVE->value,
        ]);

        ServiceOrderMale::create([
            'company_id'       => $this->company->id,
            'service_order_id' => $order->id,
            'male_caravan_id'  => $this->bull2->id,
            'status'           => ServiceOrderMaleStatus::ACTIVE->value,
        ]);

        return $order;
    }

    public function test_close_service_order_with_common_destination_batch(): void
    {
        $order = $this->createApprovedOrder();
        $withdrawalDate = '2026-12-30';

        $response = $this->apiAs('POST', "/service-orders/{$order->id}/close-service", [
            'withdrawal_date'              => $withdrawalDate,
            'default_destination_batch_id' => $this->restingBatch1->id,
            'observations'                 => 'Toros retirados en excelente estado a descanso.',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('order.status', ServiceOrderStatus::SUCCESS->value);
        $response->assertJsonPath('order.actual_end_date', $withdrawalDate);

        // Verify order persisted in DB
        $freshOrder = $order->fresh();
        $this->assertEquals(ServiceOrderStatus::SUCCESS->value, $freshOrder->status);
        $this->assertEquals($withdrawalDate, $freshOrder->actual_end_date?->format('Y-m-d'));
        $this->assertStringContainsString('Toros retirados en excelente estado', (string) $freshOrder->observations);

        // Verify bulls status in service_order_males
        $som1 = ServiceOrderMale::where('service_order_id', $order->id)
            ->where('male_caravan_id', $this->bull1->id)
            ->first();
        $this->assertEquals(ServiceOrderMaleStatus::COMPLETED->value, $som1->status);
        $this->assertNotNull($som1->retired_at);

        $som2 = ServiceOrderMale::where('service_order_id', $order->id)
            ->where('male_caravan_id', $this->bull2->id)
            ->first();
        $this->assertEquals(ServiceOrderMaleStatus::COMPLETED->value, $som2->status);

        // Verify physical caravan relocation
        $this->assertEquals($this->restingBatch1->id, $this->bull1->fresh()->batch_id);
        $this->assertEquals($this->restingBatch1->id, $this->bull2->fresh()->batch_id);

        // Verify caravan movements recorded
        $movements = CaravanMovement::where('company_id', $this->company->id)
            ->whereIn('caravan_id', [$this->bull1->id, $this->bull2->id])
            ->where('type', 'TRANSFER')
            ->get();

        $this->assertCount(2, $movements);
        foreach ($movements as $mov) {
            $this->assertEquals($this->serviceBatch->id, $mov->from_batch_id);
            $this->assertEquals($this->restingBatch1->id, $mov->to_batch_id);
        }
    }

    public function test_close_service_order_with_individual_bull_destinations(): void
    {
        $order = $this->createApprovedOrder();
        $withdrawalDate = '2026-12-28';

        $response = $this->apiAs('POST', "/service-orders/{$order->id}/close-service", [
            'withdrawal_date'   => $withdrawalDate,
            'bull_destinations' => [
                [
                    'male_caravan_id'      => $this->bull1->id,
                    'destination_batch_id' => $this->restingBatch1->id,
                ],
                [
                    'male_caravan_id'      => $this->bull2->id,
                    'destination_batch_id' => $this->restingBatch2->id,
                ],
            ],
            'observations'      => 'Toro 2 enviado a enfermería para tratamiento.',
        ]);

        $response->assertStatus(200);

        // Bull 1 went to restingBatch1
        $this->assertEquals($this->restingBatch1->id, $this->bull1->fresh()->batch_id);

        // Bull 2 went to restingBatch2
        $this->assertEquals($this->restingBatch2->id, $this->bull2->fresh()->batch_id);
    }

    public function test_cannot_close_already_completed_order(): void
    {
        $order = $this->createApprovedOrder();
        $order->update(['status' => ServiceOrderStatus::SUCCESS->value]);

        $response = $this->apiAs('POST', "/service-orders/{$order->id}/close-service", [
            'withdrawal_date'              => '2026-12-30',
            'default_destination_batch_id' => $this->restingBatch1->id,
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('Solo se pueden finalizar órdenes de servicio aprobadas', (string) $response->json('message'));
    }
}
