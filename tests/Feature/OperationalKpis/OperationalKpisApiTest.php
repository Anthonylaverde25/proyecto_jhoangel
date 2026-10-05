<?php

declare(strict_types=1);

namespace Tests\Feature\OperationalKpis;

use App\Core\Enums\EntryOrderStatus;
use App\Core\Enums\TransferOrderStatus;
use App\Models\Activity;
use App\Models\AnimalCategory;
use App\Models\Batch;
use App\Models\BatchType;
use App\Models\BirthOrder;
use App\Models\EntryOrder;
use App\Models\TransferOrder;
use App\Models\WeaningOrder;
use Tests\Feature\EntryOrders\EntryOrderTestCase;

class OperationalKpisApiTest extends EntryOrderTestCase
{
    public function test_operational_kpis_endpoint_requires_authentication(): void
    {
        $response = $this->getJson("http://{$this->host}/api/dashboard/operational-kpis");
        $response->assertStatus(401);
    }

    public function test_operational_kpis_endpoint_returns_correct_structure_and_counts(): void
    {
        $pendingEntryStatuses = [
            EntryOrderStatus::AWAITING_DTE->value,
            EntryOrderStatus::IN_TRANSIT->value,
            EntryOrderStatus::DRAFT->value,
        ];
        $pendingOrderStatuses = [
            TransferOrderStatus::ISSUED->value,
            TransferOrderStatus::PARTIAL->value,
            TransferOrderStatus::DRAFT->value,
        ];

        // Conteo inicial del seeder
        $initialEntryPending = EntryOrder::where('company_id', $this->company->id)->whereIn('status', $pendingEntryStatuses)->count();
        $initialEntryHeads = (int) EntryOrder::where('company_id', $this->company->id)->whereIn('status', $pendingEntryStatuses)->sum('head_count');

        $initialBirthPending = BirthOrder::where('company_id', $this->company->id)->whereIn('status', $pendingOrderStatuses)->count();
        $initialBirthHeads = (int) BirthOrder::where('company_id', $this->company->id)->whereIn('status', $pendingOrderStatuses)->sum('planned_head_count');

        $initialTransferPending = TransferOrder::where('company_id', $this->company->id)->whereIn('status', $pendingOrderStatuses)->count();
        $initialTransferHeads = (int) TransferOrder::where('company_id', $this->company->id)->whereIn('status', $pendingOrderStatuses)->sum('planned_head_count');

        $initialWeaningPending = WeaningOrder::where('company_id', $this->company->id)->whereIn('status', $pendingOrderStatuses)->count();
        $initialWeaningHeads = (int) WeaningOrder::where('company_id', $this->company->id)->whereIn('status', $pendingOrderStatuses)->sum('planned_head_count');

        $activity = Activity::first() ?? Activity::create(['name' => 'Invernada', 'code' => 'INV']);
        $batchType = BatchType::first() ?? BatchType::create(['name' => 'Engorde', 'code' => 'ENG']);
        $category = AnimalCategory::first() ?? AnimalCategory::create([
            'name' => 'Ternero',
            'code' => 'TERNERO',
            'sex' => 'MALE',
            'min_age_months' => 6,
            'max_age_months' => 12,
            'min_weight_kg' => 120,
            'max_weight_kg' => 220,
            'is_reproductive' => false,
        ]);
        $batch = Batch::first() ?? Batch::create([
            'company_id' => $this->company->id,
            'name' => 'Lote Origen Test',
            'activity_id' => $activity->id,
            'batch_type_id' => $batchType->id,
        ]);

        // 1. Crear órdenes de entrada
        EntryOrder::create([
            'company_id' => $this->company->id,
            'code' => 'EN-TEST-0001',
            'number' => 101,
            'status' => EntryOrderStatus::IN_TRANSIT->value,
            'provider_id' => $this->provider->id,
            'farm_id' => $this->farm->id,
            'batch_name' => 'Batch-101',
            'activity_id' => $activity->id,
            'batch_type_id' => $batchType->id,
            'head_count' => 15,
            'category_id' => $category->id,
            'sex_composition' => 'MALE',
            'condition' => 'GOOD',
            'purchase_date' => now()->toDateString(),
        ]);

        EntryOrder::create([
            'company_id' => $this->company->id,
            'code' => 'EN-TEST-0002',
            'number' => 102,
            'status' => EntryOrderStatus::AWAITING_DTE->value,
            'provider_id' => $this->provider->id,
            'farm_id' => $this->farm->id,
            'batch_name' => 'Batch-102',
            'activity_id' => $activity->id,
            'batch_type_id' => $batchType->id,
            'head_count' => 20,
            'category_id' => $category->id,
            'sex_composition' => 'MALE',
            'condition' => 'GOOD',
            'purchase_date' => now()->toDateString(),
        ]);

        // Orden completada (no debe sumar a pendientes)
        EntryOrder::create([
            'company_id' => $this->company->id,
            'code' => 'EN-TEST-0003',
            'number' => 103,
            'status' => EntryOrderStatus::COMPLETED->value,
            'provider_id' => $this->provider->id,
            'farm_id' => $this->farm->id,
            'batch_name' => 'Batch-103',
            'activity_id' => $activity->id,
            'batch_type_id' => $batchType->id,
            'head_count' => 30,
            'category_id' => $category->id,
            'sex_composition' => 'MALE',
            'condition' => 'GOOD',
            'purchase_date' => now()->toDateString(),
        ]);

        // 2. Crear orden de parición
        BirthOrder::create([
            'company_id' => $this->company->id,
            'code' => 'PA-TEST-0001',
            'status' => TransferOrderStatus::ISSUED->value,
            'planned_head_count' => 25,
            'period_start' => now()->toDateString(),
            'period_end' => now()->addDays(30)->toDateString(),
        ]);

        // 3. Crear orden de transferencia
        TransferOrder::create([
            'company_id' => $this->company->id,
            'source_batch_id' => $batch->id,
            'destination_activity_id' => $activity->id,
            'destination_mode' => 'single',
            'code' => 'TR-TEST-0001',
            'status' => TransferOrderStatus::ISSUED->value,
            'planned_head_count' => 12,
            'movement_date' => now()->toDateString(),
        ]);

        // 4. Crear orden de destete
        WeaningOrder::create([
            'company_id' => $this->company->id,
            'destination_activity_id' => $activity->id,
            'destination_mode' => 'single',
            'code' => 'DS-TEST-0001',
            'status' => TransferOrderStatus::ISSUED->value,
            'planned_head_count' => 18,
            'weaning_date' => now()->toDateString(),
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("http://{$this->host}/api/dashboard/operational-kpis");

        $response->assertStatus(200);

        $response->assertJsonStructure([
            'summary' => [
                'total_pending_documents',
                'last_updated_at',
            ],
            'categories' => [
                'entry_orders' => [
                    'key',
                    'template_code',
                    'title',
                    'subtitle',
                    'icon',
                    'color',
                    'total_pending',
                    'total_planned_heads',
                    'breakdown',
                    'items',
                ],
                'birth_orders',
                'transfer_orders',
                'weaning_orders',
            ],
        ]);

        $data = $response->json();

        // Verificar deltas sumados
        $this->assertEquals($initialEntryPending + 2, $data['categories']['entry_orders']['total_pending']);
        $this->assertEquals($initialEntryHeads + 35, $data['categories']['entry_orders']['total_planned_heads']);

        $this->assertEquals($initialBirthPending + 1, $data['categories']['birth_orders']['total_pending']);
        $this->assertEquals($initialBirthHeads + 25, $data['categories']['birth_orders']['total_planned_heads']);

        $this->assertEquals($initialTransferPending + 1, $data['categories']['transfer_orders']['total_pending']);
        $this->assertEquals($initialTransferHeads + 12, $data['categories']['transfer_orders']['total_planned_heads']);

        $this->assertEquals($initialWeaningPending + 1, $data['categories']['weaning_orders']['total_pending']);
        $this->assertEquals($initialWeaningHeads + 18, $data['categories']['weaning_orders']['total_planned_heads']);
    }
}
