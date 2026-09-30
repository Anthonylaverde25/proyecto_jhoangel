<?php

declare(strict_types=1);

namespace Tests\Feature\BirthOrders;

use App\Models\Batch;
use App\Models\BirthOrderAnimal;
use App\Models\Caravan;
use App\Models\CaravanLineage;

/**
 * What the PAR-01 processing checks on each row, and what it decides the system knows better than
 * the paper: where the calf is born and who its sire is.
 */
class Par01SheetTest extends BirthOrderTestCase
{
    public function test_the_calf_is_born_where_its_mother_is_now(): void
    {
        $a = $this->pregnantFemale('BO-G1');
        $orderId = (int) $this->emit([$a])->json('id');

        // The mother is moved to the calving paddock after the order was issued.
        $paddock = Batch::create([
            'company_id' => $this->company->id,
            'name' => 'Potrero Parición BO',
            'activity_id' => $this->activityId('CRIA'),
            'is_confined' => false,
            'is_active' => true,
        ]);
        $a->update(['batch_id' => $paddock->id]);

        $response = $this->scan($orderId, [$this->liveRow($a, 'BO-GC1')])->assertStatus(201);

        $this->assertSame($paddock->id, (int) Caravan::where('identification', 'BO-GC1')->value('batch_id'));
        $this->assertContains('MOTHER_MOVED', array_column($response->json('data.warnings'), 'code'));
    }

    public function test_every_problem_is_reported_per_row_and_nothing_is_saved(): void
    {
        Caravan::create(['company_id' => $this->company->id, 'identification' => 'BO-TAKEN', 'sex' => 'M']);
        $a = $this->pregnantFemale('BO-H1');
        $b = $this->pregnantFemale('BO-H2');
        $c = $this->pregnantFemale('BO-H3');
        $d = $this->pregnantFemale('BO-H4');
        $orderId = (int) $this->emit([$a, $b, $c, $d])->json('id');

        $response = $this->scan($orderId, [
            // Calf data written but no outcome marked.
            ['caravana_madre' => $a->identification, 'caravana_cria' => 'BO-HC1', 'sexo' => 'M', 'fecha_nacimiento' => now()->toDateString()],
            // Tag already in use.
            $this->liveRow($b, 'BO-TAKEN'),
            // No date: never taken from the header.
            [...$this->liveRow($c, 'BO-HC3'), 'fecha_nacimiento' => null],
            // Future date and unknown sex.
            [...$this->liveRow($d, 'BO-HC4', 'X'), 'fecha_nacimiento' => now()->addDays(3)->toDateString()],
        ])->assertStatus(422);

        $codes = [];
        foreach ($response->json('row_errors') as $row) {
            foreach ($row['errors'] as $error) {
                $codes[] = $error['code'];
            }
        }

        $this->assertContains('OUTCOME_MISSING', $codes);
        $this->assertContains('CALF_TAG_IN_USE', $codes);
        $this->assertContains('DATE_MISSING', $codes);
        $this->assertContains('FUTURE_DATE', $codes);
        $this->assertContains('CALF_SEX_UNKNOWN', $codes);
        $this->assertSame(0, Caravan::where('identification', 'like', 'BO-HC%')->count());
        $this->assertSame(4, BirthOrderAnimal::where('birth_order_id', $orderId)->where('status', 'PENDING')->count());
    }

    public function test_an_unplanned_calving_joins_the_order(): void
    {
        $a = $this->pregnantFemale('BO-I1');
        $surprise = Caravan::create([
            'company_id' => $this->company->id,
            'batch_id' => $this->breedingB->id,
            'identification' => 'BO-I-SURPRISE',
            'sex' => 'H',
            'category_id' => $this->categoryId('VACA'),
        ]);
        $orderId = (int) $this->emit([$a])->json('id');

        // Without the "Fuera de orden" box the tag could just as well be a misreading: it is not
        // taken as a calving outside the plan.
        $this->scan($orderId, [
            ['caravana_madre' => $a->identification],
            $this->liveRow($surprise, 'BO-IC1', 'H'),
        ])->assertStatus(422)->assertJsonPath('row_errors.0.errors.0.code', 'OUTSIDE_ORDER_NOT_DECLARED');

        $response = $this->scan($orderId, [
            ['caravana_madre' => $a->identification],
            [...$this->liveRow($surprise, 'BO-IC1', 'H'), 'fuera_de_orden' => 'X'],
        ])->assertStatus(201);

        $this->assertSame(1, $response->json('data.unplanned_count'));

        $order = $this->apiAs('GET', "/birth-orders/{$orderId}")->assertOk();
        $this->assertSame('PARTIAL', $order->json('status'));
        $this->assertSame(1, $order->json('planned_head_count'));
        $this->assertSame(2, $order->json('head_count'));
        $this->assertSame($this->breedingB->id, (int) Caravan::where('identification', 'BO-IC1')->value('batch_id'));
    }

    public function test_a_row_already_resolved_is_refused(): void
    {
        $a = $this->pregnantFemale('BO-J1');
        $b = $this->pregnantFemale('BO-J2');
        $orderId = (int) $this->emit([$a, $b])->json('id');

        $this->scan($orderId, [$this->liveRow($a, 'BO-JC1')])->assertStatus(201);

        $this->scan($orderId, [$this->liveRow($a, 'BO-JC2')])
            ->assertStatus(422)
            ->assertJsonPath('row_errors.0.errors.0.code', 'ALREADY_RESOLVED');
    }

    public function test_the_sire_is_resolved_by_the_gestation_when_left_empty(): void
    {
        $bull = $this->bull('BO-TORO-1');
        $bullB = $this->bull('BO-TORO-2');
        $single = $this->pregnantFemale('BO-K1', sires: [$bull]);
        $several = $this->pregnantFemale('BO-K2', sires: [$bull, $bullB]);
        $orderId = (int) $this->emit([$single, $several])->json('id');

        // The resource suggests the single sire, and none when there are several.
        $order = $this->apiAs('GET', "/birth-orders/{$orderId}")->assertOk();
        $suggested = array_column($order->json('animals'), 'suggested_sire_id', 'identification');
        $this->assertSame($bull->id, $suggested['BO-K1']);
        $this->assertNull($suggested['BO-K2']);

        $this->scan($orderId, [
            $this->liveRow($single, 'BO-KC1'),
            $this->liveRow($several, 'BO-KC2', 'H'),
        ])->assertStatus(201);

        $this->assertSame($bull->id, (int) CaravanLineage::where('caravan_id', Caravan::where('identification', 'BO-KC1')->value('id'))->value('father_id'));
        // Several candidates and none declared: it waits in "Sires pendientes".
        $this->assertNull(CaravanLineage::where('caravan_id', Caravan::where('identification', 'BO-KC2')->value('id'))->value('father_id'));
    }

    public function test_a_declared_sire_must_be_a_male(): void
    {
        $a = $this->pregnantFemale('BO-L1');
        $cow = $this->pregnantFemale('BO-L2');
        $orderId = (int) $this->emit([$a])->json('id');

        $this->scan($orderId, [[...$this->liveRow($a, 'BO-LC1'), 'father_id' => $cow->id]])
            ->assertStatus(422)
            ->assertJsonPath('row_errors.0.errors.0.code', 'FATHER_NOT_MALE');
    }
}
