<?php

declare(strict_types=1);

namespace Tests\Feature\ServiceOrders;

use App\Models\Activity;
use App\Models\Batch;
use App\Models\Caravan;
use App\Models\CaravanGestation;
use App\Models\CaravanMovement;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderFemale;
use App\Models\ServiceOrderMale;
use Tests\Feature\Veterinary\VeterinaryTestCase;

/**
 * "Nueva orden" and "Registrar orden": one form, one order per batch. Today or earlier starts the
 * service, a future day leaves it planned, and a service that already ended is registered with the
 * trip of its bulls on their real days.
 *
 * ANTHONY-TORO-TEST-004..006 are seeded APT.
 */
class ServiceOrderIssuanceTest extends VeterinaryTestCase
{
    private Batch $rodeoA;
    private Batch $rodeoB;
    private Batch $bullPaddock;

    protected function setUp(): void
    {
        parent::setUp();

        $cria = Activity::updateOrCreate(['code' => 'CRIA'], ['name' => 'Cría']);

        $this->rodeoA = $this->batch('Rodeo A Test', $cria->id);
        $this->rodeoB = $this->batch('Rodeo B Test', $cria->id);
        $this->bullPaddock = $this->batch('Torada Test', $cria->id);
    }

    public function test_one_form_starts_one_order_per_batch_and_moves_each_bull_to_its_batch(): void
    {
        $this->cow('ISS-A-1', $this->rodeoA);
        $this->cow('ISS-B-1', $this->rodeoB);
        $bullA = $this->bullIn('ANTHONY-TORO-TEST-004', $this->bullPaddock);
        $bullB = $this->bullIn('ANTHONY-TORO-TEST-005', $this->bullPaddock);

        $response = $this->issue([
            ['batch_id' => $this->rodeoA->id, 'male_caravan_ids' => [$bullA->id], 'service_type' => 'single'],
            ['batch_id' => $this->rodeoB->id, 'male_caravan_ids' => [$bullB->id], 'service_type' => 'single'],
        ], today: true);

        $response->assertStatus(201);
        $this->assertFalse($response->json('planned'));
        $this->assertCount(2, $response->json('orders'));
        $this->assertSame('APPROVED', $response->json('orders.0.status'));
        $this->assertSame('ISSUED', $response->json('orders.0.kind'));
        $this->assertSame($this->rodeoA->id, $bullA->fresh()->batch_id);
        $this->assertSame($this->rodeoB->id, $bullB->fresh()->batch_id);
    }

    public function test_if_one_batch_fails_no_order_exists_and_no_bull_moved(): void
    {
        $this->cow('ISS-A-1', $this->rodeoA);
        $bull = $this->bullIn('ANTHONY-TORO-TEST-004', $this->bullPaddock);

        // The second batch has no empty female to serve.
        $response = $this->issue([
            ['batch_id' => $this->rodeoA->id, 'male_caravan_ids' => [$bull->id]],
            ['batch_id' => $this->rodeoB->id, 'male_caravan_ids' => [$this->bullIn('ANTHONY-TORO-TEST-005', $this->bullPaddock)->id]],
        ], today: true);

        $response->assertStatus(422)->assertJsonStructure(['errors' => ['lots.1']]);
        $this->assertSame(0, $this->ordersOn($this->rodeoA, $this->rodeoB));
        $this->assertSame($this->bullPaddock->id, $bull->fresh()->batch_id);
    }

    public function test_a_bull_cannot_be_chosen_in_two_batches(): void
    {
        $this->cow('ISS-A-1', $this->rodeoA);
        $this->cow('ISS-B-1', $this->rodeoB);
        $bull = $this->bullIn('ANTHONY-TORO-TEST-004', $this->bullPaddock);

        $this->issue([
            ['batch_id' => $this->rodeoA->id, 'male_caravan_ids' => [$bull->id]],
            ['batch_id' => $this->rodeoB->id, 'male_caravan_ids' => [$bull->id]],
        ], today: true)->assertStatus(422)->assertJsonStructure(['errors' => ['lots.1']]);

        $this->assertSame(0, $this->ordersOn($this->rodeoA, $this->rodeoB));
    }

    public function test_insemination_serves_the_picked_females_of_each_batch_without_bulls(): void
    {
        $cowA = $this->cow('ISS-A-1', $this->rodeoA);
        $this->cow('ISS-A-2', $this->rodeoA);
        $cowB = $this->cow('ISS-B-1', $this->rodeoB);

        $response = $this->issue([
            ['batch_id' => $this->rodeoA->id, 'selected_female_caravan_ids' => [$cowA->id]],
            ['batch_id' => $this->rodeoB->id, 'selected_female_caravan_ids' => [$cowB->id]],
        ], today: true, method: 'AI');

        $response->assertStatus(201);
        $this->assertSame('AI', $response->json('orders.0.service_method'));
        $this->assertSame([$cowA->id], $response->json('orders.0.female_caravan_ids'));
        $this->assertSame([], $response->json('orders.0.male_caravan_ids'));
    }

    public function test_a_future_date_leaves_the_order_planned_moving_nothing_and_taking_nothing(): void
    {
        $cow = $this->cow('ISS-A-1', $this->rodeoA);
        $bull = $this->bullIn('ANTHONY-TORO-TEST-004', $this->bullPaddock);

        $response = $this->issue([['batch_id' => $this->rodeoA->id, 'male_caravan_ids' => [$bull->id]]], today: false);

        $response->assertStatus(201);
        $this->assertTrue($response->json('planned'));
        $this->assertSame('PLANNED', $response->json('orders.0.status'));
        $this->assertSame([$cow->id], $response->json('orders.0.female_caravan_ids'));
        $this->assertNull($response->json('orders.0.actual_start_date'));
        $this->assertSame($this->bullPaddock->id, $bull->fresh()->batch_id);
        $this->assertSame(0, CaravanMovement::where('caravan_id', $bull->id)->where('to_batch_id', $this->rodeoA->id)->count());
        $this->assertNull(ServiceOrderFemale::where('female_caravan_id', $cow->id)->value('exposed_from'));

        // The batch and the bull stay free: a service can start on them today.
        $this->apiAs('POST', "/batches/{$this->rodeoA->id}/start-service", [
            'male_caravan_ids' => [$bull->id],
            'planned_start_date' => now()->toDateString(),
        ])->assertStatus(201);
    }

    public function test_what_would_block_today_is_only_a_warning_when_planning(): void
    {
        $this->cow('ISS-A-1', $this->rodeoA);
        $this->cow('ISS-B-1', $this->rodeoB);
        $bull = $this->bullIn('ANTHONY-TORO-TEST-004', $this->bullPaddock);
        $this->apiAs('POST', "/batches/{$this->rodeoA->id}/start-service", [
            'male_caravan_ids' => [$bull->id],
            'planned_start_date' => now()->toDateString(),
        ])->assertStatus(201);

        $response = $this->issue([['batch_id' => $this->rodeoB->id, 'male_caravan_ids' => [$bull->id]]], today: false);

        $response->assertStatus(201);
        $this->assertStringContainsString('otra orden activa', implode(' ', $response->json('warnings')));
    }

    public function test_a_batch_waiting_for_its_tacto_cannot_start_another_order(): void
    {
        $cow = $this->cow('ISS-A-1', $this->rodeoA);
        $bull = $this->bullIn('ANTHONY-TORO-TEST-004', $this->bullPaddock);
        $next = $this->bullIn('ANTHONY-TORO-TEST-005', $this->bullPaddock);
        $code = $this->register($this->rodeoA, [$cow->id], [$this->stay($bull, $this->bullPaddock, $this->bullPaddock)])
            ->assertStatus(201)
            ->json('orders.0.code');

        $response = $this->issue([['batch_id' => $this->rodeoA->id, 'male_caravan_ids' => [$next->id]]], today: true);

        $response->assertStatus(422);
        $this->assertStringContainsString("{$code} sin cerrar", implode(' ', array_merge(...array_values($response->json('errors')))));
        $this->assertSame(1, $this->ordersOn($this->rodeoA));
        $this->assertSame($this->bullPaddock->id, (int) $next->fresh()->batch_id);
    }

    public function test_a_batch_waiting_for_its_tacto_can_plan_with_a_warning_but_not_start_it(): void
    {
        $cow = $this->cow('ISS-A-1', $this->rodeoA);
        $bull = $this->bullIn('ANTHONY-TORO-TEST-004', $this->bullPaddock);
        $next = $this->bullIn('ANTHONY-TORO-TEST-005', $this->bullPaddock);
        $code = $this->register($this->rodeoA, [$cow->id], [$this->stay($bull, $this->bullPaddock, $this->bullPaddock)])
            ->json('orders.0.code');

        $planned = $this->issue([['batch_id' => $this->rodeoA->id, 'male_caravan_ids' => [$next->id]]], today: false);

        $planned->assertStatus(201);
        $this->assertStringContainsString("{$code} sin cerrar", implode(' ', $planned->json('warnings')));

        $start = $this->apiAs('POST', "/service-orders/{$planned->json('orders.0.id')}/start", ['start_date' => now()->toDateString()]);

        $start->assertStatus(422);
        $this->assertStringContainsString("{$code} sin cerrar", (string) $start->json('message'));
    }

    public function test_the_batch_is_free_again_once_its_order_is_closed(): void
    {
        $cow = $this->cow('ISS-A-1', $this->rodeoA);
        $bull = $this->bullIn('ANTHONY-TORO-TEST-004', $this->bullPaddock);
        $next = $this->bullIn('ANTHONY-TORO-TEST-005', $this->bullPaddock);
        $order = $this->register($this->rodeoA, [$cow->id], [$this->stay($bull, $this->bullPaddock, $this->bullPaddock)])->json('orders.0');

        ServiceOrder::query()->whereKey($order['id'])->update(['status' => 'SUCCESS']);

        $this->issue([['batch_id' => $this->rodeoA->id, 'male_caravan_ids' => [$next->id]]], today: true)->assertStatus(201);
    }

    public function test_the_batch_tells_which_order_keeps_it_from_starting_another(): void
    {
        $cow = $this->cow('ISS-A-1', $this->rodeoA);
        $bull = $this->bullIn('ANTHONY-TORO-TEST-004', $this->bullPaddock);
        $code = $this->register($this->rodeoA, [$cow->id], [$this->stay($bull, $this->bullPaddock, $this->bullPaddock)])
            ->json('orders.0.code');

        $response = $this->apiAs('GET', "/batches/{$this->rodeoA->id}");

        $response->assertOk();
        $this->assertSame($code, $response->json('unclosed_service_order.code') ?? $response->json('data.unclosed_service_order.code'));
        $this->assertNull($response->json('active_service_order') ?? $response->json('data.active_service_order'));
    }

    public function test_starting_a_planned_order_keeps_its_code_and_applies_the_rules_on_that_day(): void
    {
        $cow = $this->cow('ISS-A-1', $this->rodeoA);
        $bull = $this->bullIn('ANTHONY-TORO-TEST-004', $this->bullPaddock);
        $planned = $this->issue([['batch_id' => $this->rodeoA->id, 'male_caravan_ids' => [$bull->id]]], today: false)->json('orders.0');
        $late = $this->cow('ISS-A-2', $this->rodeoA);

        $response = $this->apiAs('POST', "/service-orders/{$planned['id']}/start", ['start_date' => now()->toDateString()]);

        $response->assertOk();
        $this->assertSame($planned['code'], $response->json('order.code'));
        $this->assertSame('APPROVED', $response->json('order.status'));
        $this->assertSame($planned['planned_start_date'], $response->json('order.planned_start_date'));
        $this->assertSame(now()->toDateString(), $response->json('order.actual_start_date'));
        $this->assertEqualsCanonicalizing([$cow->id, $late->id], $response->json('order.female_caravan_ids'));
        $this->assertSame($this->rodeoA->id, $bull->fresh()->batch_id);
        $this->assertSame(1, $this->ordersOn($this->rodeoA));
    }

    public function test_a_planned_order_cannot_start_when_its_bull_is_taken(): void
    {
        $this->cow('ISS-A-1', $this->rodeoA);
        $this->cow('ISS-B-1', $this->rodeoB);
        $bull = $this->bullIn('ANTHONY-TORO-TEST-004', $this->bullPaddock);
        $planned = $this->issue([['batch_id' => $this->rodeoA->id, 'male_caravan_ids' => [$bull->id]]], today: false)->json('orders.0');
        $this->apiAs('POST', "/batches/{$this->rodeoB->id}/start-service", [
            'male_caravan_ids' => [$bull->id],
            'planned_start_date' => now()->toDateString(),
        ])->assertStatus(201);

        $this->apiAs('POST', "/service-orders/{$planned['id']}/start", ['start_date' => now()->toDateString()])->assertStatus(422);

        $this->assertSame('PLANNED', ServiceOrder::find($planned['id'])->status);
    }

    public function test_a_planned_order_starts_on_a_real_day_never_a_future_one(): void
    {
        $this->cow('ISS-A-1', $this->rodeoA);
        $bull = $this->bullIn('ANTHONY-TORO-TEST-004', $this->bullPaddock);
        $planned = $this->issue([['batch_id' => $this->rodeoA->id, 'male_caravan_ids' => [$bull->id]]], today: false)->json('orders.0');

        $this->apiAs('POST', "/service-orders/{$planned['id']}/start", ['start_date' => now()->addDay()->toDateString()])->assertStatus(422);
        $this->apiAs('POST', "/service-orders/{$planned['id']}/approve", ['approve' => true])->assertStatus(422);
    }

    public function test_a_planned_order_can_be_edited_and_cancelled(): void
    {
        $this->cow('ISS-A-1', $this->rodeoA);
        $bull = $this->bullIn('ANTHONY-TORO-TEST-004', $this->bullPaddock);
        $other = $this->bullIn('ANTHONY-TORO-TEST-005', $this->bullPaddock);
        $planned = $this->issue([['batch_id' => $this->rodeoA->id, 'male_caravan_ids' => [$bull->id]]], today: false)->json('orders.0');
        $newDate = now()->addDays(20)->toDateString();

        $edited = $this->apiAs('PATCH', "/service-orders/{$planned['id']}/planned", [
            'start_date' => $newDate,
            'observations' => 'Se pasa una semana',
            'male_caravan_ids' => [$other->id],
        ]);

        $edited->assertOk();
        $this->assertSame($newDate, $edited->json('order.planned_start_date'));
        $this->assertSame([$other->id], $edited->json('order.male_caravan_ids'));

        $this->apiAs('PATCH', "/service-orders/{$planned['id']}/status", ['status' => 'CANCELLED'])->assertOk();
        $this->assertSame('CANCELLED', ServiceOrder::find($planned['id'])->status);
    }

    public function test_registering_leaves_the_trip_of_the_bull_on_its_real_days_and_waits_for_the_tacto(): void
    {
        $cow = $this->cow('REG-A-1', $this->rodeoA);
        $bull = $this->bullIn('ANTHONY-TORO-TEST-004', $this->rodeoB);

        $response = $this->register($this->rodeoA, [$cow->id], [$this->stay($bull, $this->bullPaddock, $this->bullPaddock)]);

        $response->assertStatus(201);
        $order = $response->json('orders.0');
        $this->assertSame('REGISTERED', $order['kind']);
        $this->assertSame('BULLS_WITHDRAWN', $order['status']);
        $this->assertSame('2026-08-01', $order['actual_start_date']);
        $this->assertSame('2026-10-01', $order['actual_end_date']);

        $movements = CaravanMovement::where('caravan_id', $bull->id)->whereIn('to_batch_id', [$this->rodeoA->id, $this->bullPaddock->id])->orderBy('movement_date')->get();
        $this->assertCount(2, $movements);
        $this->assertSame($this->bullPaddock->id, $movements[0]->from_batch_id);
        $this->assertSame($this->rodeoA->id, $movements[0]->to_batch_id);
        $this->assertSame('2026-08-01', $movements[0]->movement_date->format('Y-m-d'));
        $this->assertSame($this->rodeoA->id, $movements[1]->from_batch_id);
        $this->assertSame($this->bullPaddock->id, $movements[1]->to_batch_id);
        $this->assertSame('2026-10-01', $movements[1]->movement_date->format('Y-m-d'));

        // Nothing newer says otherwise: the bull is where it went.
        $this->assertSame($this->bullPaddock->id, $bull->fresh()->batch_id);

        $female = ServiceOrderFemale::where('service_order_id', $order['id'])->firstOrFail();
        $this->assertSame('2026-08-01', $female->exposed_from->format('Y-m-d'));
        $this->assertSame('2026-10-01', $female->exposed_until->format('Y-m-d'));
        $this->assertNull($female->snapshot_entry_weight);
        $this->assertSame('COMPLETED', ServiceOrderMale::where('service_order_id', $order['id'])->value('status'));
    }

    public function test_a_later_movement_keeps_the_bull_where_it_is_today(): void
    {
        $cow = $this->cow('REG-A-1', $this->rodeoA);
        $bull = $this->bullIn('ANTHONY-TORO-TEST-004', $this->rodeoB);
        CaravanMovement::create([
            'company_id' => $this->company->id,
            'caravan_id' => $bull->id,
            'renspa' => 'NO_DEFINIDO',
            'type' => 'TRANSFER',
            'movement_date' => '2026-10-15',
            'from_batch_id' => $this->bullPaddock->id,
            'to_batch_id' => $this->rodeoB->id,
        ]);

        $this->register($this->rodeoA, [$cow->id], [$this->stay($bull, $this->bullPaddock, $this->bullPaddock)])->assertStatus(201);

        $this->assertSame($this->rodeoB->id, $bull->fresh()->batch_id);
        $this->assertSame(3, CaravanMovement::where('caravan_id', $bull->id)->whereDate('movement_date', '>=', '2026-08-01')->count());
    }

    public function test_a_bull_already_in_the_batch_has_no_entry_movement(): void
    {
        $cow = $this->cow('REG-A-1', $this->rodeoA);
        $bull = $this->bullIn('ANTHONY-TORO-TEST-004', $this->rodeoA);

        $this->register($this->rodeoA, [$cow->id], [$this->stay($bull, $this->rodeoA, $this->bullPaddock)])->assertStatus(201);

        $this->assertSame(1, CaravanMovement::where('caravan_id', $bull->id)->whereDate('movement_date', '>=', '2026-08-01')->count());
        $this->assertSame($this->bullPaddock->id, $bull->fresh()->batch_id);
    }

    public function test_registering_does_not_take_a_batch_with_an_active_service(): void
    {
        $cow = $this->cow('REG-A-1', $this->rodeoA);
        $active = $this->bullIn('ANTHONY-TORO-TEST-005', $this->bullPaddock);
        $orderId = $this->apiAs('POST', "/batches/{$this->rodeoA->id}/start-service", [
            'male_caravan_ids' => [$active->id],
            'planned_start_date' => now()->toDateString(),
        ])->assertStatus(201)->json('id');
        $bull = $this->bullIn('ANTHONY-TORO-TEST-004', $this->bullPaddock);

        $this->register($this->rodeoA, [$cow->id], [$this->stay($bull, $this->bullPaddock, $this->bullPaddock)])->assertStatus(201);

        $this->assertSame('APPROVED', ServiceOrder::find($orderId)->status);
    }

    public function test_conflicts_are_searched_by_date(): void
    {
        $cow = $this->cow('REG-A-1', $this->rodeoA);
        $bull = $this->bullIn('ANTHONY-TORO-TEST-004', $this->bullPaddock);
        $this->register($this->rodeoA, [$cow->id], [$this->stay($bull, $this->bullPaddock, $this->bullPaddock)])->assertStatus(201);

        // Same batch, cow and bull on overlapping days.
        $response = $this->register(
            $this->rodeoA,
            [$cow->id],
            [$this->stay($bull, $this->bullPaddock, $this->bullPaddock, '2026-09-01', '2026-10-20')],
            '2026-09-01',
            '2026-10-20'
        );
        $response->assertStatus(422)->assertJsonStructure(['errors' => ['lots.0', 'lots.0.females', 'lots.0.bulls.0.male_caravan_id']]);

        // Handing over on the day it left is no overlap.
        $this->register(
            $this->rodeoA,
            [$cow->id],
            [$this->stay($bull, $this->bullPaddock, $this->bullPaddock, '2026-10-01', '2026-10-20')],
            '2026-10-01',
            '2026-10-20'
        )->assertStatus(201);
    }

    public function test_a_female_with_a_current_pregnancy_is_not_registered(): void
    {
        $cow = $this->cow('REG-A-1', $this->rodeoA);
        CaravanGestation::create(['caravan_id' => $cow->id, 'start_date' => '2026-10-20', 'is_current' => true]);
        $bull = $this->bullIn('ANTHONY-TORO-TEST-004', $this->bullPaddock);

        $this->register($this->rodeoA, [$cow->id], [$this->stay($bull, $this->bullPaddock, $this->bullPaddock)])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['lots.0.females']]);
    }

    public function test_a_bull_that_stays_with_the_females_did_not_end_the_service(): void
    {
        $cow = $this->cow('REG-A-1', $this->rodeoA);
        $bull = $this->bullIn('ANTHONY-TORO-TEST-004', $this->bullPaddock);

        $this->register($this->rodeoA, [$cow->id], [$this->stay($bull, $this->bullPaddock, $this->rodeoA)])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['lots.0.bulls.0.destination_batch_id']]);
        $this->assertSame(0, $this->ordersOn($this->rodeoA));
    }

    public function test_registration_needs_past_dates(): void
    {
        $cow = $this->cow('REG-A-1', $this->rodeoA);
        $bull = $this->bullIn('ANTHONY-TORO-TEST-004', $this->bullPaddock);
        $tomorrow = now()->addDay()->toDateString();

        $this->register($this->rodeoA, [$cow->id], [$this->stay($bull, $this->bullPaddock, $this->bullPaddock, '2026-08-01', $tomorrow)], '2026-08-01', $tomorrow)
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['end_date']]);
    }

    public function test_a_registered_insemination_is_born_ended_without_bulls(): void
    {
        $cow = $this->cow('REG-A-1', $this->rodeoA);

        $response = $this->register($this->rodeoA, [$cow->id], [], method: 'AI');

        $response->assertStatus(201);
        $this->assertSame('SERVICE_ENDED', $response->json('orders.0.status'));
        $this->assertSame('AI', $response->json('orders.0.service_method'));
    }

    public function test_the_tacto_of_a_registered_order_never_replaces_a_current_pregnancy(): void
    {
        $cow = $this->cow('REG-A-1', $this->rodeoA);
        $bull = $this->bullIn('ANTHONY-TORO-TEST-004', $this->bullPaddock);
        $orderId = $this->register($this->rodeoA, [$cow->id], [$this->stay($bull, $this->bullPaddock, $this->bullPaddock)])->json('orders.0.id');
        $current = CaravanGestation::create(['caravan_id' => $cow->id, 'start_date' => '2026-10-20', 'is_current' => true]);

        foreach ([true, false] as $pregnant) {
            $this->apiAs('POST', "/caravans/{$cow->id}/gestation-diagnosis", [
                'service_order_id' => $orderId,
                'is_pregnant' => $pregnant,
                'gestation_stage' => $pregnant ? 'head' : null,
                'diagnosis_date' => '2026-10-25',
            ])->assertStatus(422)->assertJsonPath('message', 'REG-A-1 tiene una preñez vigente: este tacto no la reemplaza. Si la perdió, registrá la pérdida primero.');
        }

        $this->assertTrue((bool) $current->fresh()->is_current);
    }

    public function test_the_tacto_of_an_issued_order_never_ends_a_pregnancy_of_another_order(): void
    {
        [$first, $second, $cow] = $this->cowInTwoOpenOrders();
        $this->tacto($cow, $first, true)->assertSuccessful();
        $current = CaravanGestation::query()->where('caravan_id', $cow->id)->where('is_current', true)->firstOrFail();
        $code = ServiceOrder::query()->whereKey($first)->value('code');

        foreach ([true, false] as $pregnant) {
            $this->tacto($cow, $second, $pregnant)
                ->assertStatus(422)
                ->assertJsonPath('message', "ISS-A-1 tiene una preñez vigente de la orden {$code}: este tacto no la reemplaza. Si la perdió, registrá la pérdida primero.");
        }

        $this->assertTrue((bool) $current->fresh()->is_current);
        $this->assertSame((int) $first, (int) $current->fresh()->service_order_id);
    }

    public function test_a_recheck_in_the_same_order_still_corrects_the_verdict(): void
    {
        [$first, , $cow] = $this->cowInTwoOpenOrders();
        $this->tacto($cow, $first, true)->assertSuccessful();

        $this->tacto($cow, $first, false)->assertSuccessful();

        $this->assertSame(0, CaravanGestation::query()->where('caravan_id', $cow->id)->where('is_current', true)->count());
    }

    public function test_once_the_loss_is_registered_the_other_order_can_load_its_tacto(): void
    {
        [$first, $second, $cow] = $this->cowInTwoOpenOrders();
        $this->tacto($cow, $first, true)->assertSuccessful();

        $this->apiAs('POST', "/caravans/{$cow->id}/gestation-loss", [
            'loss_reason_id' => \Illuminate\Support\Facades\DB::table('gestation_loss_reasons')->value('id'),
            'loss_date' => now()->toDateString(),
        ])->assertStatus(200);

        $this->tacto($cow, $second, false)->assertSuccessful();
    }

    public function test_a_female_moved_with_her_tacto_pending_cannot_be_picked_but_a_natural_service_warns(): void
    {
        [$cow] = $this->cowOwingTactoMovedTo($this->rodeoB);
        $bull = $this->bullIn('ANTHONY-TORO-TEST-005', $this->bullPaddock);

        $picked = $this->issue([[
            'batch_id' => $this->rodeoB->id,
            'male_caravan_ids' => [$bull->id],
            'is_controlled_service' => true,
            'selected_female_caravan_ids' => [$cow->id],
        ]], today: true);
        $picked->assertStatus(422);
        $this->assertStringContainsString('ISS-A-1', implode(' ', array_merge(...array_values($picked->json('errors')))));

        $natural = $this->issue([['batch_id' => $this->rodeoB->id, 'male_caravan_ids' => [$bull->id]]], today: true);
        $natural->assertStatus(201);
        $this->assertContains($cow->id, $natural->json('orders.0.female_caravan_ids'));
        $this->assertStringContainsString('ISS-A-1', implode(' ', $natural->json('warnings')));
    }

    public function test_a_planned_order_leaves_out_a_picked_female_that_owes_a_tacto_when_it_starts(): void
    {
        $this->cow('ISS-B-9', $this->rodeoB);
        $keeps = $this->cow('ISS-B-8', $this->rodeoB);
        $bull = $this->bullIn('ANTHONY-TORO-TEST-005', $this->bullPaddock);
        [$cow] = $this->cowOwingTactoMovedTo($this->rodeoB);
        $planned = $this->issue([[
            'batch_id' => $this->rodeoB->id,
            'male_caravan_ids' => [$bull->id],
            'is_controlled_service' => true,
            'selected_female_caravan_ids' => [$cow->id, $keeps->id],
        ]], today: false)->assertStatus(201);
        $this->assertStringContainsString('ISS-A-1', implode(' ', $planned->json('warnings')));

        $start = $this->apiAs('POST', "/service-orders/{$planned->json('orders.0.id')}/start", ['start_date' => now()->toDateString()]);

        $start->assertOk();
        $this->assertSame([$keeps->id], $start->json('order.female_caravan_ids'));
        $this->assertStringContainsString('tacto pendiente', implode(' ', $start->json('warnings')));
    }

    public function test_the_sheet_can_read_who_owes_a_tacto(): void
    {
        [$cow, $code] = $this->cowOwingTactoMovedTo($this->rodeoB);

        $this->apiAs('GET', '/service-orders/pending-tacto-females')
            ->assertOk()
            ->assertJsonFragment(['caravan_id' => $cow->id, 'order_code' => $code]);
    }

    public function test_the_tacto_of_a_registered_order_loads_when_she_has_no_other_pregnancy(): void
    {
        $cow = $this->cow('REG-A-1', $this->rodeoA);
        $bull = $this->bullIn('ANTHONY-TORO-TEST-004', $this->bullPaddock);
        $orderId = $this->register($this->rodeoA, [$cow->id], [$this->stay($bull, $this->bullPaddock, $this->bullPaddock)])->json('orders.0.id');

        $this->apiAs('POST', "/caravans/{$cow->id}/gestation-diagnosis", [
            'service_order_id' => $orderId,
            'is_pregnant' => true,
            'gestation_stage' => 'head',
            'diagnosis_date' => '2026-10-25',
        ])->assertOk();

        $this->assertSame('PREGNANT', ServiceOrderFemale::where('service_order_id', $orderId)->value('reproductive_status'));
    }

    public function test_bull_locations_read_the_movements_of_the_day(): void
    {
        $bull = $this->bullIn('ANTHONY-TORO-TEST-004', $this->rodeoB);
        foreach ([['2026-05-01', $this->rodeoB, $this->bullPaddock], ['2026-09-01', $this->bullPaddock, $this->rodeoB]] as [$day, $from, $to]) {
            CaravanMovement::create([
                'company_id' => $this->company->id,
                'caravan_id' => $bull->id,
                'renspa' => 'NO_DEFINIDO',
                'type' => 'TRANSFER',
                'movement_date' => $day,
                'from_batch_id' => $from->id,
                'to_batch_id' => $to->id,
            ]);
        }

        $location = $this->apiAs('GET', '/service-orders/bull-locations?' . http_build_query([
            'caravan_ids' => [$bull->id],
            'on_day' => '2026-08-01',
            'moved_after' => '2026-08-15',
        ]))->assertOk()->json('locations.0');

        $this->assertSame($this->bullPaddock->id, $location['batch_id']);
        $this->assertSame($this->rodeoB->id, $location['current_batch_id']);
        $this->assertTrue($location['moved_after']);
    }

    /** @return array{0: int, 1: int, 2: Caravan} two open orders holding the same cow */
    private function cowInTwoOpenOrders(): array
    {
        $cow = $this->cow('ISS-A-1', $this->rodeoA);
        $bull = $this->bullIn('ANTHONY-TORO-TEST-004', $this->bullPaddock);
        $next = $this->bullIn('ANTHONY-TORO-TEST-005', $this->bullPaddock);
        $first = (int) $this->register($this->rodeoA, [$cow->id], [$this->stay($bull, $this->bullPaddock, $this->bullPaddock)])->json('orders.0.id');
        $cow->update(['batch_id' => $this->rodeoB->id]);
        $second = (int) $this->issue([['batch_id' => $this->rodeoB->id, 'male_caravan_ids' => [$next->id]]], today: true)
            ->assertStatus(201)
            ->json('orders.0.id');

        return [$first, $second, $cow];
    }

    /** @return array{0: Caravan, 1: string} a cow in another batch, still owing the tacto of her order */
    private function cowOwingTactoMovedTo(Batch $batch): array
    {
        $cow = $this->cow('ISS-A-1', $this->rodeoA);
        $bull = $this->bullIn('ANTHONY-TORO-TEST-004', $this->bullPaddock);
        $code = (string) $this->register($this->rodeoA, [$cow->id], [$this->stay($bull, $this->bullPaddock, $this->bullPaddock)])->json('orders.0.code');
        $cow->update(['batch_id' => $batch->id]);

        return [$cow, $code];
    }

    private function tacto(Caravan $cow, int $orderId, bool $pregnant)
    {
        return $this->apiAs('POST', "/caravans/{$cow->id}/gestation-diagnosis", [
            'service_order_id' => $orderId,
            'is_pregnant' => $pregnant,
            'gestation_stage' => $pregnant ? 'head' : null,
            'diagnosis_date' => now()->toDateString(),
        ]);
    }

    private function ordersOn(Batch ...$batches): int
    {
        return ServiceOrder::whereIn('batch_id', array_map(fn (Batch $batch) => $batch->id, $batches))->count();
    }

    /** @param list<array<string, mixed>> $lots */
    private function issue(array $lots, bool $today, string $method = 'NATURAL')
    {
        return $this->apiAs('POST', '/service-orders/issue', [
            'service_method' => $method,
            'start_date' => $today ? now()->toDateString() : now()->addDays(7)->toDateString(),
            'lots' => $lots,
        ]);
    }

    /**
     * @param int[] $femaleIds
     * @param list<array<string, mixed>> $bulls
     */
    private function register(Batch $batch, array $femaleIds, array $bulls, string $start = '2026-08-01', string $end = '2026-10-01', string $method = 'NATURAL')
    {
        return $this->apiAs('POST', '/service-orders/register', [
            'service_method' => $method,
            'start_date' => $start,
            'end_date' => $end,
            'lots' => [['batch_id' => $batch->id, 'female_caravan_ids' => $femaleIds, 'bulls' => $bulls]],
        ]);
    }

    /** @return array<string, mixed> */
    private function stay(Caravan $bull, Batch $origin, Batch $destination, string $joined = '2026-08-01', string $retired = '2026-10-01'): array
    {
        return [
            'male_caravan_id' => $bull->id,
            'origin_batch_id' => $origin->id,
            'joined_at' => $joined,
            'retired_at' => $retired,
            'destination_batch_id' => $destination->id,
        ];
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

    private function cow(string $tag, Batch $batch): Caravan
    {
        return Caravan::create([
            'company_id' => $this->company->id,
            'batch_id' => $batch->id,
            'identification' => $tag,
            'sex' => 'H',
            'teeth' => 4,
        ]);
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
