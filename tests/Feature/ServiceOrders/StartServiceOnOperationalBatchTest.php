<?php

declare(strict_types=1);

namespace Tests\Feature\ServiceOrders;

use App\Core\Enums\PhysiologicalState;
use App\Core\Interfaces\ICaravanRepository;
use App\Models\Activity;
use App\Models\AnimalCategory;
use App\Models\Batch;
use App\Models\Caravan;
use App\Models\CaravanGestation;
use App\Models\CaravanMovement;
use App\Models\Company;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderFemale;
use Tests\Feature\Veterinary\VeterinaryTestCase;

/**
 * A service is an order over an operational Cría batch: the females stay in their batch, the
 * bulls join them, and the exposure of every female follows where she physically is.
 *
 * ANTHONY-TORO-TEST-004..006 are seeded APT.
 */
class StartServiceOnOperationalBatchTest extends VeterinaryTestCase
{
    private Batch $rodeo;
    private Batch $neighbour;
    private Batch $bullPaddock;

    protected function setUp(): void
    {
        parent::setUp();

        $cria = Activity::updateOrCreate(['code' => 'CRIA'], ['name' => 'Cría']);

        $this->rodeo = $this->batch('Rodeo Servicio Test', $cria->id);
        $this->neighbour = $this->batch('Rodeo Vecino Test', $cria->id);
        $this->bullPaddock = $this->batch('Torada Descanso Test', $cria->id);
    }

    public function test_natural_service_exposes_every_empty_female_and_only_the_bull_moves(): void
    {
        $cows = [$this->cow('SRV-V-01'), $this->cow('SRV-V-02'), $this->cow('SRV-V-03')];
        $pregnant = $this->cow('SRV-V-04');
        CaravanGestation::create(['caravan_id' => $pregnant->id, 'start_date' => now()->subMonths(3)->toDateString(), 'is_current' => true]);
        $bull = $this->bullIn('ANTHONY-TORO-TEST-004', $this->bullPaddock);

        // Picking a single cow is ignored under natural service: the bull reaches all of them.
        $response = $this->start([$bull->id], [$cows[0]->id]);

        $response->assertStatus(201);
        $this->assertMatchesRegularExpression('/^SO-\d{4}-\d{3}$/', $response->json('code'));
        $this->assertSame($this->rodeo->id, $response->json('batch_id'));
        $this->assertSame('Rodeo Servicio Test', $response->json('batch_name'));
        $this->assertEqualsCanonicalizing(
            array_map(fn (Caravan $c) => $c->id, $cows),
            $response->json('female_caravan_ids')
        );

        foreach ($cows as $cow) {
            $this->assertSame($this->rodeo->id, $cow->fresh()->batch_id);
            $this->assertSame(0, CaravanMovement::where('caravan_id', $cow->id)->count());
        }

        $this->assertSame($this->rodeo->id, $bull->fresh()->batch_id);
        $this->assertDatabaseHas('caravan_movements', [
            'caravan_id' => $bull->id,
            'from_batch_id' => $this->bullPaddock->id,
            'to_batch_id' => $this->rodeo->id,
        ]);

        // No batch is created for the service, and the batch reports the running service.
        $this->assertSame(0, Batch::withoutGlobalScopes()->where('name', 'like', '%Servicio SO-%')->count());
        $batch = $this->apiAs('GET', "/batches/{$this->rodeo->id}")->assertStatus(200);
        $this->assertTrue($batch->json('is_in_service'));
        $this->assertSame($response->json('code'), $batch->json('active_service_order.code'));
    }

    public function test_natural_service_exposes_a_female_the_owner_excluded_because_she_shares_the_paddock(): void
    {
        $cow = $this->cow('SRV-EX-01');
        $excluded = $this->cow('SRV-EX-02');
        $excluded->update(['is_breeding_eligible' => false, 'breeding_exclusion_reason' => 'CUT_DENTITION']);
        $bull = $this->bullIn('ANTHONY-TORO-TEST-004', $this->bullPaddock);

        $response = $this->start([$bull->id])->assertStatus(201);

        $this->assertEqualsCanonicalizing([$cow->id, $excluded->id], $response->json('female_caravan_ids'));
        $this->assertDatabaseHas('service_order_males', [
            'service_order_id' => $response->json('id'),
            'male_caravan_id' => $bull->id,
            'joined_at' => '2026-11-01',
            'sanitary_status_at_entry' => 'APT',
        ]);
    }

    public function test_controlled_service_leaves_excluded_females_out_and_rejects_picking_one(): void
    {
        $cow = $this->cow('SRV-EX-03');
        $excluded = $this->cow('SRV-EX-04');
        $excluded->update(['is_breeding_eligible' => false, 'breeding_exclusion_reason' => 'SCHEDULED_FOR_SALE']);
        $bull = $this->bullIn('ANTHONY-TORO-TEST-005', $this->bullPaddock);

        $rejected = $this->start([$bull->id], [$cow->id, $excluded->id], controlled: true);
        $rejected->assertStatus(422);
        $this->assertStringContainsString('excluidos de reproducción', (string) $rejected->json('message'));

        $response = $this->start([$bull->id], null, controlled: true)->assertStatus(201);
        $this->assertSame([$cow->id], $response->json('female_caravan_ids'));
    }

    public function test_a_calf_at_foot_is_never_exposed_in_natural_or_controlled_service(): void
    {
        $cow = $this->cow('SRV-CAT-01');
        $heifer = $this->cow('SRV-CAT-02');
        $heifer->update(['category_id' => $this->category('VAQUILLONA', 'Vaquillona', 'H', true)->id]);
        $calf = $this->cow('SRV-CAT-03');
        $calf->update(['category_id' => $this->category('TERNERO', 'Ternero', 'BOTH', false)->id, 'teeth' => 0]);
        $bull = $this->bullIn('ANTHONY-TORO-TEST-004', $this->bullPaddock);

        $natural = $this->start([$bull->id])->assertStatus(201);
        $this->assertEqualsCanonicalizing([$cow->id, $heifer->id], $natural->json('female_caravan_ids'));

        $this->apiAs('POST', "/service-orders/{$natural->json('id')}/close-service", [
            'withdrawal_date' => '2026-12-30',
            'default_destination_batch_id' => $this->bullPaddock->id,
        ])->assertStatus(200);

        $controlled = $this->start([$bull->id], null, '2027-01-15', controlled: true)->assertStatus(201);
        $this->assertNotContains($calf->id, $controlled->json('female_caravan_ids'));
    }

    public function test_only_a_breeding_category_enters_as_a_bull(): void
    {
        $this->cow('SRV-CAT-10');
        $steer = $this->bullIn('ANTHONY-TORO-TEST-004', $this->bullPaddock);
        $steer->update(['category_id' => $this->category('NOVILLO', 'Novillo', 'M', false)->id]);

        $rejected = $this->start([$steer->id])->assertStatus(422);
        $this->assertStringContainsString('es Novillo', (string) $rejected->json('message'));

        $youngBull = $this->bullIn('ANTHONY-TORO-TEST-005', $this->bullPaddock);
        $youngBull->update(['category_id' => $this->category('TORITO', 'Torito', 'M', true)->id]);

        $this->start([$youngBull->id])->assertStatus(201);
    }

    public function test_a_batch_cannot_start_a_second_service_while_one_is_running(): void
    {
        $this->cow('SRV-V-10');
        $bull1 = $this->bullIn('ANTHONY-TORO-TEST-004', $this->bullPaddock);
        $bull2 = $this->bullIn('ANTHONY-TORO-TEST-005', $this->bullPaddock);

        $this->start([$bull1->id])->assertStatus(201);

        $response = $this->start([$bull2->id]);

        $response->assertStatus(422);
        $this->assertStringContainsString('ya tiene un servicio activo', (string) $response->json('message'));
        $this->assertSame(1, ServiceOrder::withoutGlobalScopes()->where('batch_id', $this->rodeo->id)->count());
    }

    public function test_service_codes_are_numbered_per_company(): void
    {
        $this->cow('SRV-V-20');
        $bull = $this->bullIn('ANTHONY-TORO-TEST-004', $this->bullPaddock);

        // Another company already uses the code this one gets next: each company has its own series.
        $next = app(\App\Core\Services\ServiceOrderCodeGenerator::class)->next((int) $this->company->id, '2026-11-01');
        $other = Company::where('id', '!=', $this->company->id)->firstOrFail();
        ServiceOrder::withoutGlobalScopes()->create([
            'company_id' => $other->id,
            'batch_id' => Batch::withoutGlobalScopes()->where('company_id', $other->id)->value('id'),
            'code' => $next,
            'status' => 'SUCCESS',
            'planned_start_date' => '2026-01-10',
        ]);

        $response = $this->start([$bull->id])->assertStatus(201);

        $this->assertSame($next, $response->json('code') ?? $response->json('data.code'));
    }

    public function test_females_entering_and_leaving_the_batch_update_their_exposure(): void
    {
        $resident = $this->cow('SRV-V-20');
        $newcomer = $this->cow('SRV-V-21', $this->neighbour);
        $bull = $this->bullIn('ANTHONY-TORO-TEST-004', $this->bullPaddock);

        $orderId = (int) $this->start([$bull->id])->assertStatus(201)->json('id');

        // A female entering the batch under natural service joins the order.
        $this->transfer([$newcomer], $this->rodeo, '2026-11-05');
        $joined = $this->exposure($orderId, $newcomer);
        $this->assertNotNull($joined);
        $this->assertTrue($joined->joined_after_start);
        $this->assertSame('2026-11-05', $joined->exposed_from?->format('Y-m-d'));
        $this->assertNull($joined->exposed_until);

        // A female leaving the batch stops being exposed.
        $this->transfer([$resident], $this->neighbour, '2026-11-10');
        $left = $this->exposure($orderId, $resident);
        $this->assertSame('2026-11-10', $left->exposed_until?->format('Y-m-d'));
        $this->assertSame('TRANSFERRED_OUT', $left->exit_reason);

        // Coming back during the same service reopens her row instead of duplicating it.
        $this->transfer([$resident], $this->rodeo, '2026-11-20');
        $this->assertSame(1, ServiceOrderFemale::where('service_order_id', $orderId)->where('female_caravan_id', $resident->id)->count());
        $this->assertNull($this->exposure($orderId, $resident)->exposed_until);
    }

    public function test_an_exposed_female_is_in_service_until_the_bulls_are_withdrawn(): void
    {
        $cow = $this->cow('SRV-V-30');
        $bull = $this->bullIn('ANTHONY-TORO-TEST-004', $this->bullPaddock);

        $orderId = (int) $this->start([$bull->id])->assertStatus(201)->json('id');

        $repository = app(ICaravanRepository::class);
        $this->assertSame(PhysiologicalState::IN_SERVICE, $repository->findById($cow->id)->getPhysiologicalState());

        // Closing without destinations: each bull goes back to the batch it came from (F1).
        $this->apiAs('POST', "/service-orders/{$orderId}/close-service", [
            'withdrawal_date' => '2026-12-30',
        ])->assertStatus(200);

        $this->assertSame($this->bullPaddock->id, $bull->fresh()->batch_id);
        $this->assertSame('BULLS_WITHDRAWN', $this->exposure($orderId, $cow)->exit_reason);
        $this->assertNotSame(PhysiologicalState::IN_SERVICE, $repository->findById($cow->id)->getPhysiologicalState());

        // The batch the bulls left is recomputed: only the cow remains.
        $this->assertSame(1, (int) $this->rodeo->fresh()->caravans_count);
    }

    public function test_the_batch_keeps_its_service_history(): void
    {
        $this->cow('SRV-V-40');
        $bull = $this->bullIn('ANTHONY-TORO-TEST-004', $this->bullPaddock);

        $first = (int) $this->start([$bull->id], null, '2025-11-01')->assertStatus(201)->json('id');
        $this->apiAs('POST', "/service-orders/{$first}/close-service", [
            'withdrawal_date' => '2026-01-30',
            'default_destination_batch_id' => $this->bullPaddock->id,
        ])->assertStatus(200);

        $second = (int) $this->start([$bull->id], null, '2026-11-01')->assertStatus(201)->json('id');

        $history = $this->apiAs('GET', "/batches/{$this->rodeo->id}/service-orders")->assertStatus(200);
        $this->assertSame([$second, $first], array_column($history->json(), 'id'));
    }

    /**
     * @param int[] $bullIds
     * @param int[]|null $femaleIds
     * @return \Illuminate\Testing\TestResponse
     */
    private function start(array $bullIds, ?array $femaleIds = null, string $startDate = '2026-11-01', bool $controlled = false)
    {
        return $this->apiAs('POST', "/batches/{$this->rodeo->id}/start-service", array_filter([
            'male_caravan_ids' => $bullIds,
            'selected_female_caravan_ids' => $femaleIds,
            'planned_start_date' => $startDate,
            'service_type' => 'single',
            'is_controlled_service' => $controlled,
        ], fn ($value) => $value !== null));
    }

    /**
     * @param Caravan[] $caravans
     */
    private function transfer(array $caravans, Batch $target, string $date): void
    {
        $this->apiAs('POST', '/caravans/bulk-transfer', [
            'caravan_ids' => array_map(fn (Caravan $c) => $c->id, $caravans),
            'target_batch_id' => $target->id,
            'movement_date' => $date,
        ])->assertStatus(200);
    }

    private function exposure(int $orderId, Caravan $cow): ?ServiceOrderFemale
    {
        return ServiceOrderFemale::where('service_order_id', $orderId)
            ->where('female_caravan_id', $cow->id)
            ->first();
    }

    private function batch(string $name, int $activityId): Batch
    {
        return Batch::create([
            'company_id' => $this->company->id,
            'name' => $name,
            'is_active' => true,
            'activity_id' => $activityId,
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
        ]);
    }

    private function category(string $code, string $name, string $sex, bool $reproductive): AnimalCategory
    {
        return AnimalCategory::updateOrCreate(['code' => $code], ['name' => $name, 'sex' => $sex, 'is_reproductive' => $reproductive]);
    }

    private function bullIn(string $tag, Batch $batch): Caravan
    {
        $bull = Caravan::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('identification', $tag)
            ->firstOrFail();
        $bull->update(['batch_id' => $batch->id]);

        return $bull;
    }
}
