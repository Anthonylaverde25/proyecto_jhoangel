<?php

declare(strict_types=1);

namespace Tests\Feature\BirthOrders;

use App\Models\BirthOrderAnimal;
use App\Models\Caravan;
use App\Models\CaravanGestation;
use App\Models\CaravanLineage;
use App\Models\GestationLossReason;

/**
 * PAR-01 results and reloads: the same sheet scanned again as the rounds fill it, the three calving
 * outcomes (V / NM / M), the overdue alert (N), and an abortion registered outside the sheet.
 */
class Par01ResultsAndReloadsTest extends BirthOrderTestCase
{
    // ── R1: reload ───────────────────────────────────────────────────────────────

    public function test_the_same_sheet_is_reloaded_as_it_fills_up(): void
    {
        $a = $this->pregnantFemale('BO-R1');
        $b = $this->pregnantFemale('BO-R2');
        $c = $this->pregnantFemale('BO-R3');
        $orderId = (int) $this->emit([$a, $b, $c])->json('id');

        $day1 = [
            $this->liveRow($a, 'BO-RC1'),
            $this->row($b, 'NM'),
            ['caravana_madre' => $c->identification],
        ];
        $this->scan($orderId, $day1)->assertStatus(201)->assertJsonPath('data.birth_order.status', 'PARTIAL');

        // Day 2: the same paper, with one more female marked. The calf tag of day 1 is not "in use".
        $day2 = [$day1[0], $day1[1], $this->row($c, 'M')];
        $response = $this->scan($orderId, $day2)->assertStatus(201);

        $this->assertSame(1, $response->json('data.resolved_count'));
        $this->assertSame(2, $response->json('data.already_registered_count'));
        $this->assertSame(1, $response->json('data.perinatal_death_count'));
        $this->assertSame('EXECUTED', $response->json('data.birth_order.status'));
        $this->assertSame(1, Caravan::where('identification', 'BO-RC1')->count());
    }

    public function test_a_reload_without_news_is_refused_with_its_own_message(): void
    {
        $a = $this->pregnantFemale('BO-S1');
        $b = $this->pregnantFemale('BO-S2');
        $orderId = (int) $this->emit([$a, $b])->json('id');
        $sheet = [$this->liveRow($a, 'BO-SC1'), ['caravana_madre' => $b->identification]];

        $this->scan($orderId, $sheet)->assertStatus(201);

        $response = $this->scan($orderId, $sheet)->assertStatus(422);
        $this->assertSame('NOTHING_RESOLVED', $response->json('header_errors.0.code'));
        $this->assertStringContainsString('ya estaba registrado', (string) $response->json('header_errors.0.message'));
    }

    // ── R2: V / NM / M ───────────────────────────────────────────────────────────

    public function test_a_calf_born_dead_is_charged_to_the_mother(): void
    {
        $a = $this->pregnantFemale('BO-T1');
        $orderId = (int) $this->emit([$a])->json('id');

        $response = $this->scan($orderId, [[...$this->row($a, 'NM'), 'sexo' => 'H', 'caravana_cria' => 'BO-TC1']])->assertStatus(201);

        $this->assertContains('CALF_DATA_IGNORED', array_column($response->json('data.warnings'), 'code'));
        $gestation = CaravanGestation::where('caravan_id', $a->id)->latest('id')->firstOrFail();
        $this->assertFalse((bool) $gestation->success);
        $this->assertSame('STILLBORN', GestationLossReason::whereKey($gestation->loss_reason_id)->value('code'));
        $this->assertSame($this->categoryId('VACA'), (int) $a->fresh()->category_id);
        $this->assertSame(0, Caravan::where('identification', 'BO-TC1')->count());

        $line = BirthOrderAnimal::where('birth_order_id', $orderId)->firstOrFail();
        $this->assertSame('LOST', $line->status);
        $this->assertSame('H', $line->calf_sex);
        $this->assertSame(1, $this->caravanJson($a)['stillborn_count']);
    }

    public function test_a_calf_that_died_at_foot_is_charged_to_the_calf(): void
    {
        $a = $this->pregnantFemale('BO-U1');
        $orderId = (int) $this->emit([$a])->json('id');

        $this->scan($orderId, [[...$this->row($a, 'M'), 'sexo' => 'M']])->assertStatus(201);

        $gestation = CaravanGestation::where('caravan_id', $a->id)->latest('id')->firstOrFail();
        $this->assertTrue((bool) $gestation->success);
        $this->assertNull($gestation->loss_reason_id);
        $this->assertSame($this->categoryId('VACA'), (int) $a->fresh()->category_id);
        $this->assertSame(0, CaravanLineage::where('mother_id', $a->id)->count());

        $line = BirthOrderAnimal::where('birth_order_id', $orderId)->firstOrFail();
        $this->assertSame('BORN_DIED', $line->status);
        $this->assertSame('PERINATAL_DEATH', $line->outcome);
        $this->assertSame('M', $line->calf_sex);
        $this->assertSame(0, $this->caravanJson($a)['stillborn_count']);
    }

    public function test_two_outcomes_together_are_refused_but_n_with_one_is_not(): void
    {
        $a = $this->pregnantFemale('BO-V1');
        $b = $this->pregnantFemale('BO-V2');
        $c = $this->pregnantFemale('BO-V3');
        $d = $this->pregnantFemale('BO-V4');
        $orderId = (int) $this->emit([$a, $b, $c, $d])->json('id');

        $response = $this->scan($orderId, [
            $this->row($a, 'NM, M'),
            [...$this->liveRow($b, 'BO-VC2'), 'resultado' => 'V, M'],
        ])->assertStatus(422);
        $this->assertSame(['OUTCOME_UNKNOWN', 'OUTCOME_UNKNOWN'], array_map(fn ($r) => $r['errors'][0]['code'], $response->json('row_errors')));

        $this->scan($orderId, [
            [...$this->liveRow($c, 'BO-VC3'), 'resultado' => 'N, V'],
            $this->row($d, 'N, NM'),
        ])->assertStatus(201);

        $this->assertSame(['BORN', 'LOST'], [
            BirthOrderAnimal::where('mother_caravan_id', $c->id)->value('status'),
            BirthOrderAnimal::where('mother_caravan_id', $d->id)->value('status'),
        ]);
    }

    public function test_an_abortion_is_not_marked_on_the_sheet(): void
    {
        $a = $this->pregnantFemale('BO-W1');
        $orderId = (int) $this->emit([$a])->json('id');

        $this->scan($orderId, [$this->row($a, 'A')])
            ->assertStatus(422)
            ->assertJsonPath('row_errors.0.errors.0.code', 'OUTCOME_NOT_ON_SHEET');
    }

    // ── R3: N, the overdue alert ─────────────────────────────────────────────────

    public function test_n_opens_an_overdue_alert_that_keeps_the_order_open(): void
    {
        $late = $this->pregnantFemale('BO-X1', dueInDays: -10);
        $b = $this->pregnantFemale('BO-X2');
        $orderId = (int) $this->emit([$late, $b])->json('id');

        $response = $this->scan($orderId, [
            $this->row($late, 'N', observations: 'Inquieta, sin signos de parto'),
            $this->liveRow($b, 'BO-XC2'),
        ])->assertStatus(201);

        $this->assertSame(1, $response->json('data.overdue_new_count'));
        // No line is PENDING any more, but the overdue one keeps the order open.
        $this->assertSame('PARTIAL', $response->json('data.birth_order.status'));
        $this->assertSame(1, $response->json('data.birth_order.overdue_head_count'));

        $line = BirthOrderAnimal::where('mother_caravan_id', $late->id)->firstOrFail();
        $this->assertSame('OVERDUE', $line->status);
        $this->assertSame(now()->toDateString(), $line->overdue_reported_at?->toDateString());
        $this->assertSame('Inquieta, sin signos de parto', $line->overdue_notes);

        $gestation = $this->currentGestation($late);
        $this->assertNotNull($gestation);
        $this->assertSame(now()->toDateString(), $gestation->calving_overdue_reported_at?->toDateString());
        $this->assertSame(0, $this->caravanJson($late)['active_gestation']['calving_overdue_days']);

        // The tray filters the orders with an overdue female.
        $quiet = (int) $this->emit([$this->pregnantFemale('BO-X3')])->json('id');
        $filtered = array_column($this->apiAs('GET', '/birth-orders?overdue=1')->json(), 'id');
        $this->assertContains($orderId, $filtered);
        $this->assertNotContains($quiet, $filtered);
        $this->assertSame('OVERDUE', collect($this->apiAs('GET', "/birth-orders/{$orderId}")->json('animals'))->firstWhere('caravan_id', $late->id)['status']);
    }

    public function test_n_before_the_due_date_is_only_a_warning(): void
    {
        $early = $this->pregnantFemale('BO-Y1', dueInDays: 20);
        $orderId = (int) $this->emit([$early])->json('id');

        $response = $this->scan($orderId, [$this->row($early, 'N')])->assertStatus(201);

        $this->assertContains('OVERDUE_BEFORE_DUE', array_column($response->json('data.warnings'), 'code'));
        $this->assertSame('OVERDUE', BirthOrderAnimal::where('mother_caravan_id', $early->id)->value('status'));
    }

    public function test_n_needs_a_current_gestation_an_order_and_no_calf_data(): void
    {
        $a = $this->pregnantFemale('BO-Z1', dueInDays: -5);
        $b = $this->pregnantFemale('BO-Z2', dueInDays: -5);
        $orderId = (int) $this->emit([$a, $b])->json('id');
        CaravanGestation::where('caravan_id', $a->id)->update(['is_current' => false]);

        $response = $this->scan($orderId, [
            $this->row($a, 'N'),
            [...$this->row($b, 'N'), 'caravana_cria' => 'BO-ZC2'],
        ])->assertStatus(422);
        $this->assertSame('NO_ACTIVE_GESTATION', $response->json('row_errors.0.errors.0.code'));
        $this->assertSame('OUTCOME_MISSING_WITH_OVERDUE', $response->json('row_errors.1.errors.0.code'));

        // A sheet printed blank serves one load: the alert has no order to stay in.
        $c = $this->pregnantFemale('BO-Z3', dueInDays: -5);
        $this->scan(null, [$this->row($c, 'N')])
            ->assertStatus(422)
            ->assertJsonPath('row_errors.0.errors.0.code', 'OVERDUE_NEEDS_ORDER');
    }

    public function test_an_overdue_female_that_calves_later_closes_the_alert(): void
    {
        $late = $this->pregnantFemale('BO-AA1', dueInDays: -10);
        $other = $this->pregnantFemale('BO-AA2', dueInDays: -10);
        $third = $this->pregnantFemale('BO-AA3');
        $orderId = (int) $this->emit([$late, $other, $third])->json('id');

        $this->scan($orderId, [
            $this->row($late, 'N', now()->subDays(6)->format('d/m/Y')),
            $this->row($other, 'N', now()->subDays(6)->format('d/m/Y')),
        ])->assertStatus(201);

        // Day 3: one of them calved. The other one is still only N: skipped, alert open.
        $response = $this->scan($orderId, [
            [...$this->liveRow($late, 'BO-AAC1', date: now()->format('d/m/Y')), 'resultado' => 'N, V'],
            $this->row($other, 'N', now()->subDays(6)->format('d/m/Y')),
            $this->liveRow($third, 'BO-AAC3'),
        ])->assertStatus(201);

        $this->assertSame(1, $response->json('data.overdue_resolved_count'));
        $this->assertSame(6, $response->json('data.overdue_resolved.0.days_after_report'));
        $this->assertSame(1, $response->json('data.overdue_open_count'));
        $this->assertSame('BORN', BirthOrderAnimal::where('mother_caravan_id', $late->id)->value('status'));
        $this->assertNull($this->currentGestation($late));
        $this->assertSame('OVERDUE', BirthOrderAnimal::where('mother_caravan_id', $other->id)->value('status'));
        $this->assertNotNull($this->currentGestation($other)?->calving_overdue_reported_at);
    }

    public function test_closing_incomplete_skips_the_overdue_line_but_keeps_the_alert(): void
    {
        $late = $this->pregnantFemale('BO-AB1', dueInDays: -10);
        $b = $this->pregnantFemale('BO-AB2');
        $orderId = (int) $this->emit([$late, $b])->json('id');
        $this->scan($orderId, [$this->row($late, 'N'), $this->liveRow($b, 'BO-ABC2')])->assertStatus(201);

        $this->apiAs('POST', "/birth-orders/{$orderId}/close-incomplete", ['reason' => 'Fin de temporada'])
            ->assertOk()
            ->assertJsonPath('status', 'CLOSED_INCOMPLETE');

        $this->assertSame('SKIPPED', BirthOrderAnimal::where('mother_caravan_id', $late->id)->value('status'));
        $this->assertNotNull($this->currentGestation($late)?->calving_overdue_reported_at);
    }

    // ── R4: an abortion registered outside the sheet ─────────────────────────────

    public function test_an_abortion_registered_outside_closes_the_line_with_its_reason(): void
    {
        $late = $this->pregnantFemale('BO-AC1', dueInDays: -10);
        $b = $this->pregnantFemale('BO-AC2');
        $c = $this->pregnantFemale('BO-AC3');
        $orderId = (int) $this->emit([$late, $b, $c])->json('id');
        $day1 = [$this->row($late, 'N'), $this->liveRow($b, 'BO-ACC2'), ['caravana_madre' => $c->identification]];
        $this->scan($orderId, $day1)->assertStatus(201);

        $abortion = (int) GestationLossReason::where('company_id', $this->company->id)->where('code', 'ABORTION')->value('id');
        $this->apiAs('POST', "/caravans/{$late->id}/gestation-loss", [
            'loss_reason_id' => $abortion,
            'loss_date' => now()->toDateString(),
        ])->assertOk();

        $line = BirthOrderAnimal::where('mother_caravan_id', $late->id)->firstOrFail();
        $this->assertSame('LOST', $line->status);
        $this->assertSame('ABORTION', $line->loss_reason_code);
        $this->assertNull($line->outcome);

        $order = $this->apiAs('GET', "/birth-orders/{$orderId}")->assertOk();
        $this->assertSame('PARTIAL', $order->json('status'));
        $this->assertSame(1, $order->json('external_loss_head_count'));
        $this->assertSame(0, $order->json('overdue_head_count'));
        $this->assertSame('GESTATION_LOSS', collect($order->json('history'))->last()['metadata']['origin']);
        $this->assertNotNull(collect($order->json('animals'))->firstWhere('caravan_id', $late->id)['loss_reason_label']);

        // A reload of the same sheet skips her; the last open female closes the order.
        $response = $this->scan($orderId, [$day1[0], $day1[1], $this->liveRow($c, 'BO-ACC3')])->assertStatus(201);
        $this->assertSame(2, $response->json('data.already_registered_count'));
        $this->assertSame('EXECUTED', $response->json('data.birth_order.status'));
    }

    public function test_an_abortion_of_the_last_open_female_executes_the_order(): void
    {
        $a = $this->pregnantFemale('BO-AD1');
        $b = $this->pregnantFemale('BO-AD2');
        $orderId = (int) $this->emit([$a, $b])->json('id');
        $this->scan($orderId, [$this->liveRow($b, 'BO-ADC2')])->assertStatus(201);

        $reabsorption = (int) GestationLossReason::where('company_id', $this->company->id)->where('code', 'REABSORPTION')->value('id');
        $this->apiAs('POST', "/caravans/{$a->id}/gestation-loss", [
            'loss_reason_id' => $reabsorption,
            'loss_date' => now()->toDateString(),
        ])->assertOk();

        $this->assertSame('REABSORPTION', BirthOrderAnimal::where('mother_caravan_id', $a->id)->value('loss_reason_code'));
        $this->assertSame('EXECUTED', $this->apiAs('GET', "/birth-orders/{$orderId}")->json('status'));
    }

    /**
     * A row with a mark and a date, without a calf.
     *
     * @return array<string, mixed>
     */
    private function row(Caravan $mother, string $mark, ?string $date = null, ?string $observations = null): array
    {
        return [
            'caravana_madre' => $mother->identification,
            'resultado' => $mark,
            'fecha_nacimiento' => $date ?? now()->format('d/m/Y'),
            'observations' => $observations,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function caravanJson(Caravan $caravan): array
    {
        return collect($this->apiAs('GET', '/caravans')->assertOk()->json())->firstWhere('id', $caravan->id) ?? [];
    }
}
