<?php

declare(strict_types=1);

namespace Tests\Feature\Paternity;

use App\Models\Activity;
use App\Models\Batch;
use App\Models\Caravan;
use App\Models\CaravanGestation;
use App\Models\CaravanLineage;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Veterinary\VeterinaryTestCase;

/**
 * "Paternidad pendiente": calves born from an order with more than one bull wait for the user to
 * confirm their sire, with the bulls that were with the mother as candidates and the estimated
 * conception as evidence.
 *
 * ANTHONY-TORO-TEST-004..006 are seeded APT.
 */
class PaternityTest extends VeterinaryTestCase
{
    private Batch $rodeo;
    private Batch $bullPaddock;

    protected function setUp(): void
    {
        parent::setUp();

        $cria = Activity::updateOrCreate(['code' => 'CRIA'], ['name' => 'Cría']);
        $this->rodeo = Batch::create(['company_id' => $this->company->id, 'name' => 'Rodeo Paternidad', 'is_active' => true, 'activity_id' => $cria->id]);
        $this->bullPaddock = Batch::create(['company_id' => $this->company->id, 'name' => 'Torada Paternidad', 'is_active' => true, 'activity_id' => $cria->id]);
    }

    public function test_a_calf_of_a_two_bull_order_waits_with_both_bulls_as_candidates(): void
    {
        [$calf, , $first, $second] = $this->calfOfTwoBulls('PT-01');

        $row = $this->pendingRow($calf);

        $this->assertSame(['from' => $this->day(-295), 'to' => $this->day(-270)], $row['conception_window']);
        $this->assertEqualsCanonicalizing([$first->id, $second->id], array_column($row['candidates'], 'id'));
        $this->assertSame([true, true], array_column($row['candidates'], 'compatible_by_date'));
    }

    public function test_a_bull_replaced_before_the_conception_is_not_compatible_by_date(): void
    {
        $cow = $this->cow('PT-02');
        [$out, $in] = [$this->bull('ANTHONY-TORO-TEST-004'), $this->bull('ANTHONY-TORO-TEST-005')];
        $orderId = $this->start([$out->id], -400);
        $this->apiAs('POST', "/service-orders/{$orderId}/replace-bull", [
            'retired_male_caravan_id' => $out->id,
            'replacement_male_caravan_id' => $in->id,
            'replacement_date' => $this->day(-320),
            'reason' => 'LAMENESS_FOOT',
            'destination_batch_id' => $this->bullPaddock->id,
        ])->assertStatus(201);
        $calf = $this->pregnantAndBorn($orderId, $cow, 'PT-02-CRIA');

        $candidates = collect($this->pendingRow($calf)['candidates'])->keyBy('id');

        $this->assertFalse($candidates[$out->id]['compatible_by_date']);
        $this->assertTrue($candidates[$in->id]['compatible_by_date']);
    }

    public function test_a_gestation_without_kept_candidates_takes_them_from_its_order(): void
    {
        [$calf, $gestationId, $first, $second] = $this->calfOfTwoBulls('PT-03');
        DB::table('gestation_sires')->where('gestation_id', $gestationId)->delete();

        $this->assertEqualsCanonicalizing([$first->id, $second->id], array_column($this->pendingRow($calf)['candidates'], 'id'));
    }

    public function test_confirming_writes_the_lineage_and_the_gestation_and_the_calf_leaves_the_list(): void
    {
        [$calf, $gestationId, $first, $second] = $this->calfOfTwoBulls('PT-04');

        $this->confirm([['calf_id' => $calf->id, 'father_id' => $second->id, 'method' => 'phenotype', 'notes' => 'Careta']])
            ->assertStatus(200)
            ->assertJson(['confirmed' => 1, 'warnings' => []]);

        $lineage = CaravanLineage::where('caravan_id', $calf->id)->firstOrFail();
        $this->assertSame($second->id, (int) $lineage->father_id);
        $this->assertSame('phenotype', $lineage->sire_identification_method);
        $this->assertSame('Careta', $lineage->sire_notes);
        $this->assertNotNull($lineage->sire_assigned_at);
        $this->assertSame(
            [$first->id => false, $second->id => true],
            DB::table('gestation_sires')->where('gestation_id', $gestationId)->orderBy('sire_id')->pluck('is_confirmed', 'sire_id')->map(fn ($v) => (bool) $v)->all()
        );
        $this->assertNull(collect($this->pending())->firstWhere('calf_id', $calf->id));
    }

    public function test_a_sire_outside_the_service_is_kept_with_a_warning(): void
    {
        [$calf, $gestationId] = $this->calfOfTwoBulls('PT-05');
        $stranger = $this->bull('ANTHONY-TORO-TEST-006');

        $response = $this->confirm([['calf_id' => $calf->id, 'father_id' => $stranger->id, 'method' => 'lab_genetic']])->assertStatus(200);

        $this->assertCount(1, $response->json('warnings'));
        $this->assertSame($stranger->id, (int) CaravanLineage::where('caravan_id', $calf->id)->value('father_id'));
        $this->assertTrue((bool) DB::table('gestation_sires')->where('gestation_id', $gestationId)->where('sire_id', $stranger->id)->value('is_confirmed'));
    }

    public function test_a_wrong_row_saves_nothing_and_names_its_cell(): void
    {
        [$calf, , $first] = $this->calfOfTwoBulls('PT-06');
        $cow = $this->cow('PT-06-HEMBRA');
        $other = $this->calfOfTwoBulls('PT-07', reuseBulls: true)[0];

        $this->confirm([
            ['calf_id' => $calf->id, 'father_id' => $first->id, 'method' => 'phenotype'],
            ['calf_id' => $other->id, 'father_id' => $cow->id, 'method' => 'phenotype'],
        ])->assertStatus(422)->assertJsonValidationErrors(['confirmations.1.father_id']);

        $this->assertNull(CaravanLineage::where('caravan_id', $calf->id)->value('father_id'));
    }

    public function test_a_calf_with_a_confirmed_sire_is_not_confirmed_again(): void
    {
        [$calf, , $first, $second] = $this->calfOfTwoBulls('PT-08');
        $this->confirm([['calf_id' => $calf->id, 'father_id' => $first->id, 'method' => 'phenotype']])->assertStatus(200);

        $this->confirm([['calf_id' => $calf->id, 'father_id' => $second->id, 'method' => 'phenotype']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['confirmations.0.calf_id']);
    }

    /**
     * A calf born today to a cow served by two bulls since 400 days ago.
     *
     * @return array{0: Caravan, 1: int, 2: Caravan, 3: Caravan}
     */
    private function calfOfTwoBulls(string $tag, bool $reuseBulls = false): array
    {
        $cow = $this->cow($tag);
        [$first, $second] = [$this->bull('ANTHONY-TORO-TEST-004'), $this->bull('ANTHONY-TORO-TEST-005')];
        if ($reuseBulls) {
            $orderId = (int) DB::table('service_order_males')->where('male_caravan_id', $first->id)->value('service_order_id');
            DB::table('service_order_females')->insert([
                'company_id' => $this->company->id, 'service_order_id' => $orderId, 'female_caravan_id' => $cow->id,
                'reproductive_status' => 'UNCHECKED', 'exposed_from' => $this->day(-400),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        } else {
            $orderId = $this->start([$first->id, $second->id], -400);
        }
        $calf = $this->pregnantAndBorn($orderId, $cow, "{$tag}-CRIA");
        $gestationId = (int) CaravanLineage::where('caravan_id', $calf->id)->value('gestation_id');

        return [$calf, $gestationId, $first, $second];
    }

    private function pregnantAndBorn(int $orderId, Caravan $cow, string $calfTag): Caravan
    {
        $this->apiAs('POST', "/caravans/{$cow->id}/gestation-diagnosis", [
            'service_order_id' => $orderId,
            'is_pregnant' => true,
            'gestation_stage' => 'head',
            'diagnosis_date' => $this->day(-200),
        ])->assertStatus(200);
        $gestationId = (int) CaravanGestation::where('caravan_id', $cow->id)->where('is_current', true)->value('id');

        $this->apiAs('POST', '/caravans/bulk-birth', ['births' => [[
            'calf_identification' => $calfTag,
            'calf_sex' => 'M',
            'calf_category' => 'ternero',
            'calf_teeth' => 0,
            'birth_date' => $this->day(0),
            'batch_id' => $this->rodeo->id,
            'mother_id' => $cow->id,
            'gestation_id' => $gestationId,
        ]]])->assertStatus(201);

        return Caravan::where('identification', $calfTag)->firstOrFail();
    }

    /** @param int[] $bullIds */
    private function start(array $bullIds, int $startOffset): int
    {
        return (int) $this->apiAs('POST', "/batches/{$this->rodeo->id}/start-service", [
            'male_caravan_ids' => $bullIds,
            'planned_start_date' => $this->day($startOffset),
            'service_type' => count($bullIds) > 1 ? 'multi' : 'single',
        ])->assertStatus(201)->json('id');
    }

    /** @return list<array<string, mixed>> */
    private function pending(): array
    {
        return $this->apiAs('GET', '/paternity/pending')->assertStatus(200)->json();
    }

    /** @return array<string, mixed> */
    private function pendingRow(Caravan $calf): array
    {
        $row = collect($this->pending())->firstWhere('calf_id', $calf->id);
        $this->assertNotNull($row, "{$calf->identification} should be pending");

        return $row;
    }

    /** @param list<array<string, mixed>> $confirmations */
    private function confirm(array $confirmations)
    {
        return $this->apiAs('POST', '/paternity/confirmations', ['confirmations' => $confirmations]);
    }

    private function cow(string $tag): Caravan
    {
        return Caravan::create(['company_id' => $this->company->id, 'batch_id' => $this->rodeo->id, 'identification' => $tag, 'sex' => 'H', 'teeth' => 4]);
    }

    private function bull(string $tag): Caravan
    {
        $bull = Caravan::withoutGlobalScopes()->where('company_id', $this->company->id)->where('identification', $tag)->firstOrFail();
        if ($bull->batch_id !== $this->rodeo->id) {
            $bull->update(['batch_id' => $this->bullPaddock->id]);
        }

        return $bull;
    }

    private function day(int $offset): string
    {
        return now()->addDays($offset)->toDateString();
    }
}
