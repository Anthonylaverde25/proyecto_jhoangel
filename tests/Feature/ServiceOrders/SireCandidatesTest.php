<?php

declare(strict_types=1);

namespace Tests\Feature\ServiceOrders;

use App\Models\Activity;
use App\Models\Batch;
use App\Models\Caravan;
use App\Models\CaravanGestation;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Veterinary\VeterinaryTestCase;

/**
 * Paternity at the chute: the bulls that were with a female become her candidate sires. The sire
 * is confirmed only when the order had a single bull all along; otherwise it is settled on the calf.
 *
 * ANTHONY-TORO-TEST-004..006 are seeded APT.
 */
class SireCandidatesTest extends VeterinaryTestCase
{
    private Batch $rodeo;
    private Batch $bullPaddock;

    protected function setUp(): void
    {
        parent::setUp();

        $cria = Activity::updateOrCreate(['code' => 'CRIA'], ['name' => 'Cría']);
        $this->rodeo = $this->batch('Rodeo Paternidad', $cria->id);
        $this->bullPaddock = $this->batch('Torada Paternidad', $cria->id);
    }

    public function test_two_bulls_are_both_candidates_and_none_is_confirmed(): void
    {
        $cow = $this->cow('PAT-01');
        [$first, $second] = [$this->bull('ANTHONY-TORO-TEST-004'), $this->bull('ANTHONY-TORO-TEST-005')];
        $orderId = $this->start([$first->id, $second->id]);

        $this->pregnant($orderId, $cow)->assertStatus(200);

        $this->assertSame([$first->id => false, $second->id => false], $this->siresOf($cow));
    }

    public function test_the_sole_bull_of_the_order_is_confirmed_at_the_chute(): void
    {
        $cow = $this->cow('PAT-02');
        $bull = $this->bull('ANTHONY-TORO-TEST-004');
        $orderId = $this->start([$bull->id]);

        $this->pregnant($orderId, $cow)->assertStatus(200);

        $this->assertSame([$bull->id => true], $this->siresOf($cow));
    }

    public function test_the_sole_bull_is_still_confirmed_after_the_withdrawal(): void
    {
        $cow = $this->cow('PAT-03');
        $bull = $this->bull('ANTHONY-TORO-TEST-004');
        $orderId = $this->start([$bull->id]);
        $this->withdraw($orderId);

        $this->pregnant($orderId, $cow)->assertStatus(200);

        $this->assertSame([$bull->id => true], $this->siresOf($cow));
    }

    public function test_a_retired_bull_stays_a_candidate_next_to_the_remaining_one(): void
    {
        $cow = $this->cow('PAT-04');
        [$retired, $remaining] = [$this->bull('ANTHONY-TORO-TEST-004'), $this->bull('ANTHONY-TORO-TEST-005')];
        $orderId = $this->start([$retired->id, $remaining->id]);

        $this->apiAs('POST', "/service-orders/{$orderId}/retire-bull", [
            'male_caravan_id' => $retired->id,
            'retirement_date' => $this->day(-60),
            'reason' => 'SANITARY_REJECTION',
            'destination_batch_id' => $this->bullPaddock->id,
        ])->assertStatus(200);

        $this->pregnant($orderId, $cow)->assertStatus(200);

        $this->assertSame([$retired->id => false, $remaining->id => false], $this->siresOf($cow));
    }

    public function test_bulls_withdrawn_before_the_chute_are_still_candidates(): void
    {
        $cow = $this->cow('PAT-05');
        [$first, $second] = [$this->bull('ANTHONY-TORO-TEST-004'), $this->bull('ANTHONY-TORO-TEST-005')];
        $orderId = $this->start([$first->id, $second->id]);
        $this->withdraw($orderId);

        $this->pregnant($orderId, $cow)->assertStatus(200);

        $this->assertSame([$first->id => false, $second->id => false], $this->siresOf($cow));
    }

    public function test_after_a_replacement_a_single_candidate_is_not_confirmed_nor_inherited_by_the_calf(): void
    {
        $cow = $this->cow('PAT-06');
        $other = $this->cow('PAT-06B');
        [$out, $in] = [$this->bull('ANTHONY-TORO-TEST-004'), $this->bull('ANTHONY-TORO-TEST-005')];
        $orderId = $this->start([$out->id]);

        // She leaves the service batch (empty at an early chute), then the bull is replaced.
        $this->apiAs('POST', "/caravans/{$cow->id}/gestation-diagnosis", [
            'service_order_id' => $orderId,
            'is_pregnant' => false,
            'diagnosis_date' => $this->day(-70),
            'empty_destination_batch_id' => $this->bullPaddock->id,
        ])->assertStatus(200);
        $this->apiAs('POST', "/service-orders/{$orderId}/replace-bull", [
            'retired_male_caravan_id' => $out->id,
            'replacement_male_caravan_id' => $in->id,
            'replacement_date' => $this->day(-60),
            'reason' => 'LAMENESS_FOOT',
            'destination_batch_id' => $this->bullPaddock->id,
        ])->assertStatus(201);

        $this->pregnant($orderId, $cow)->assertStatus(200);
        $this->pregnant($orderId, $other)->assertStatus(200);

        $this->assertSame([$out->id => false], $this->siresOf($cow));
        $this->assertSame([$out->id => false, $in->id => false], $this->siresOf($other));

        $gestationId = (int) CaravanGestation::where('caravan_id', $cow->id)->where('is_current', true)->value('id');
        $this->apiAs('POST', '/caravans/bulk-birth', ['births' => [[
            'calf_identification' => 'PAT-06-CRIA',
            'calf_sex' => 'H',
            'calf_category' => 'ternera',
            'calf_teeth' => 0,
            'birth_date' => $this->day(0),
            'batch_id' => $this->rodeo->id,
            'mother_id' => $cow->id,
            'gestation_id' => $gestationId,
        ]]])->assertStatus(201);

        $calfId = (int) Caravan::where('identification', 'PAT-06-CRIA')->value('id');
        $this->assertNull(\App\Models\CaravanLineage::where('caravan_id', $calfId)->firstOrFail()->father_id);
    }

    public function test_the_chute_does_not_take_a_sire_from_the_user(): void
    {
        $cow = $this->cow('PAT-07');
        [$first, $second] = [$this->bull('ANTHONY-TORO-TEST-004'), $this->bull('ANTHONY-TORO-TEST-005')];
        $orderId = $this->start([$first->id, $second->id]);

        $this->pregnant($orderId, $cow, $second->id)->assertStatus(200);

        $this->assertSame([$first->id => false, $second->id => false], $this->siresOf($cow));
    }

    public function test_the_diagnosis_screen_lists_the_candidates_and_the_sole_bull(): void
    {
        $cow = $this->cow('PAT-08');
        [$first, $second] = [$this->bull('ANTHONY-TORO-TEST-004'), $this->bull('ANTHONY-TORO-TEST-005')];
        $orderId = $this->start([$first->id, $second->id]);
        $this->pregnant($orderId, $cow)->assertStatus(200);

        $diagnosis = $this->apiAs('GET', "/service-orders/{$orderId}/diagnosis")->assertStatus(200);
        $female = collect($diagnosis->json('females'))->firstWhere('female_caravan_id', $cow->id);

        $this->assertNull($diagnosis->json('sole_bull_id'));
        $this->assertEqualsCanonicalizing(
            [['id' => $first->id, 'identification' => 'ANTHONY-TORO-TEST-004'], ['id' => $second->id, 'identification' => 'ANTHONY-TORO-TEST-005']],
            $female['sire_candidates']
        );
        $this->assertNull($female['confirmed_sire_id']);
    }

    private function withdraw(int $orderId): void
    {
        $this->apiAs('POST', "/service-orders/{$orderId}/close-service", [
            'withdrawal_date' => $this->day(-50),
            'default_destination_batch_id' => $this->bullPaddock->id,
        ])->assertStatus(200);
    }

    /**
     * @param int[] $bullIds
     */
    private function start(array $bullIds): int
    {
        return (int) $this->apiAs('POST', "/batches/{$this->rodeo->id}/start-service", [
            'male_caravan_ids' => $bullIds,
            'planned_start_date' => $this->day(-90),
            'service_type' => count($bullIds) > 1 ? 'multi' : 'single',
        ])->assertStatus(201)->json('id');
    }

    private function pregnant(int $orderId, Caravan $cow, ?int $confirmedSireId = null)
    {
        return $this->apiAs('POST', "/caravans/{$cow->id}/gestation-diagnosis", array_filter([
            'service_order_id' => $orderId,
            'is_pregnant' => true,
            'gestation_stage' => 'head',
            'diagnosis_date' => $this->day(-5),
            'confirmed_sire_id' => $confirmedSireId,
        ], fn ($value) => $value !== null));
    }

    /** @return array<int, bool> sire id => confirmed, ordered by sire id */
    private function siresOf(Caravan $cow): array
    {
        $gestation = CaravanGestation::where('caravan_id', $cow->id)->where('is_current', true)->firstOrFail();

        return DB::table('gestation_sires')
            ->where('gestation_id', $gestation->id)
            ->orderBy('sire_id')
            ->get(['sire_id', 'is_confirmed'])
            ->mapWithKeys(fn ($row) => [(int) $row->sire_id => (bool) $row->is_confirmed])
            ->all();
    }

    private function batch(string $name, int $activityId): Batch
    {
        return Batch::create(['company_id' => $this->company->id, 'name' => $name, 'is_active' => true, 'activity_id' => $activityId]);
    }

    private function cow(string $tag): Caravan
    {
        return Caravan::create(['company_id' => $this->company->id, 'batch_id' => $this->rodeo->id, 'identification' => $tag, 'sex' => 'H', 'teeth' => 4]);
    }

    private function bull(string $tag): Caravan
    {
        $bull = Caravan::withoutGlobalScopes()->where('company_id', $this->company->id)->where('identification', $tag)->firstOrFail();
        $bull->update(['batch_id' => $this->bullPaddock->id]);

        return $bull;
    }

    private function day(int $offset): string
    {
        return now()->addDays($offset)->toDateString();
    }
}
