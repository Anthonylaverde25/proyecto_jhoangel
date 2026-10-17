<?php

declare(strict_types=1);

namespace Tests\Feature\Batches;

use App\Models\Activity;
use App\Models\Batch;
use App\Models\Caravan;
use App\Models\CaravanGestation;
use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Tests\Feature\EntryOrders\EntryOrderTestCase;

/**
 * GET /batches/{id}/overview: what the batch page shows above its caravans.
 */
class BatchOverviewApiTest extends EntryOrderTestCase
{
    public function test_a_cria_batch_shows_its_composition_and_reproductive_state(): void
    {
        $batch = $this->criaBatch('Rodeo Cría Overview');
        $pregnant = $this->animal('OV-H-001', $batch, 'H', 'VACA');
        $overdue = $this->animal('OV-H-002', $batch, 'H', 'VACA');
        $this->animal('OV-H-003', $batch, 'H', 'VAQUILLONA');
        $this->animal('OV-M-001', $batch, 'M', 'TORO');
        CaravanGestation::create(['caravan_id' => $pregnant->id, 'is_current' => true, 'start_date' => now()->subMonths(5)->toDateString()]);
        $orderId = $this->birthOrder('PAR-OV-1', 'ISSUED', [$overdue->id => 'OVERDUE']);

        $overview = $this->apiAs('GET', "/batches/{$batch->id}/overview")->assertOk()->json();

        $this->assertSame(['M' => 1, 'H' => 3], $overview['sex_composition']);
        $this->assertSame(['females' => 3, 'pregnant' => 1, 'without_gestation' => 2, 'overdue' => 1], $overview['reproductive']);
        $this->assertSame(2, $overview['category_composition'][0]['count']);
        // The life of an animal comes first.
        $this->assertSame('OVERDUE_BIRTH', $overview['alerts'][0]['type']);
        $this->assertSame('error', $overview['alerts'][0]['severity']);
        $this->assertSame(['kind' => 'birth_order', 'id' => $orderId, 'code' => 'PAR-OV-1'], $overview['alerts'][0]['link']);
    }

    public function test_an_overdue_calving_stays_an_alert_after_its_order_is_closed(): void
    {
        $batch = $this->criaBatch('Rodeo Cría Cerrado');
        $cow = $this->animal('OV-H-010', $batch, 'H', 'VACA');
        $this->birthOrder('PAR-OV-2', 'CLOSED_INCOMPLETE', [$cow->id => 'OVERDUE']);

        $alerts = $this->apiAs('GET', "/batches/{$batch->id}/overview")->json('alerts');

        $this->assertSame(['OVERDUE_BIRTH'], array_column($alerts, 'type'));
    }

    public function test_an_animal_that_left_the_batch_no_longer_raises_its_alerts(): void
    {
        $batch = $this->criaBatch('Rodeo Cría Origen');
        $other = $this->ownBatch('Rodeo Destino Overview');
        $cow = $this->animal('OV-H-020', $batch, 'H', 'VACA');
        $this->birthOrder('PAR-OV-3', 'ISSUED', [$cow->id => 'OVERDUE']);

        $cow->update(['batch_id' => $other->id]);

        $overview = $this->apiAs('GET', "/batches/{$batch->id}/overview")->json();
        $this->assertSame([], $overview['alerts']);
        $this->assertSame(0, $overview['reproductive']['overdue']);
    }

    public function test_a_pending_line_of_an_open_order_is_an_info_alert(): void
    {
        $batch = $this->criaBatch('Rodeo Cría Pendiente');
        $cow = $this->animal('OV-H-030', $batch, 'H', 'VACA');
        $this->birthOrder('PAR-OV-4', 'ISSUED', [$cow->id => 'PENDING']);

        $alert = $this->apiAs('GET', "/batches/{$batch->id}/overview")->json('alerts.0');

        $this->assertSame('PENDING_ORDER', $alert['type']);
        $this->assertSame('info', $alert['severity']);
        $this->assertSame(1, $alert['count']);
        $this->assertSame('birth_order', $alert['link']['kind']);
    }

    public function test_an_empty_batch_has_no_aggregates_and_no_reproductive_block_outside_cria(): void
    {
        $batch = $this->ownBatch('Lote Vacío Overview');

        $overview = $this->apiAs('GET', "/batches/{$batch->id}/overview")->assertOk()->json();

        $this->assertSame(['M' => 0, 'H' => 0], $overview['sex_composition']);
        $this->assertSame([], $overview['category_composition']);
        $this->assertNull($overview['last_weighing_date']);
        $this->assertNull($overview['reproductive']);
        $this->assertSame([], $overview['alerts']);
    }

    public function test_a_purchase_on_its_way_and_its_open_incidents_are_warnings(): void
    {
        $batch = $this->ownBatch('Recría Destino Overview');
        $order = $this->createOrder(['destinations' => [$this->destination(1, $batch->id)]])->assertCreated()->json('order');
        $this->incident((int) $order['id'], 'OPEN');
        $this->incident((int) $order['id'], 'RESOLVED');

        $alerts = $this->apiAs('GET', "/batches/{$batch->id}/overview")->json('alerts');

        $this->assertSame(['ENTRY_INCIDENT', 'INCOMING_PURCHASE'], array_column($alerts, 'type'));
        $this->assertSame(1, $alerts[0]['count']);
        $this->assertSame(['kind' => 'entry_order', 'id' => (int) $order['id'], 'code' => $order['code']], $alerts[0]['link']);
        $this->assertSame('warning', $alerts[1]['severity']);
    }

    public function test_a_batch_of_another_company_is_not_found(): void
    {
        $other = Company::create(['name' => 'Otra Empresa Overview', 'is_active' => true]);
        $foreign = Batch::withoutGlobalScopes()->create(['company_id' => $other->id, 'name' => 'Ajeno', 'is_active' => true]);

        $this->apiAs('GET', "/batches/{$foreign->id}/overview")->assertNotFound();
        $this->apiAs('GET', '/batches/999999/overview')->assertNotFound();
    }

    private function criaBatch(string $name): Batch
    {
        $activity = Activity::withoutGlobalScopes()->where('code', 'CRIA')->first()
            ?? Activity::create(['code' => 'CRIA', 'name' => 'Cría']);
        $batch = $this->ownBatch($name);
        $batch->update(['activity_id' => $activity->id]);

        return $batch;
    }

    private function animal(string $identification, Batch $batch, string $sex, string $category): Caravan
    {
        return Caravan::create([
            'company_id' => $this->company->id,
            'identification' => $identification,
            'batch_id' => $batch->id,
            'sex' => $sex,
            'category_id' => $this->categoryId($category),
        ]);
    }

    /**
     * @param array<int, string> $lines mother caravan id => line status
     */
    private function birthOrder(string $code, string $status, array $lines): int
    {
        $orderId = (int) DB::table('birth_orders')->insertGetId([
            'company_id' => $this->company->id,
            'code' => $code,
            'status' => $status,
            'planned_head_count' => count($lines),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($lines as $motherId => $lineStatus) {
            DB::table('birth_order_animals')->insert([
                'company_id' => $this->company->id,
                'birth_order_id' => $orderId,
                'mother_caravan_id' => $motherId,
                'status' => $lineStatus,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $orderId;
    }

    private function incident(int $entryOrderId, string $status): void
    {
        DB::table('entry_order_incidents')->insert([
            'company_id' => $this->company->id,
            'entry_order_id' => $entryOrderId,
            'type' => 'MISSING_HEAD',
            'detail' => 'Faltan cabezas respecto del DTE',
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
