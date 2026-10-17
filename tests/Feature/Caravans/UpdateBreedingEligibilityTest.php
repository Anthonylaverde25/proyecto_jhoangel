<?php

declare(strict_types=1);

namespace Tests\Feature\Caravans;

use App\Models\Activity;
use App\Models\Batch;
use App\Models\Caravan;
use App\Models\CaravanBreedingEligibilityChange;
use Tests\Feature\Veterinary\VeterinaryTestCase;

/**
 * The owner marks and unmarks animals as fit for breeding, without the veterinary module.
 */
class UpdateBreedingEligibilityTest extends VeterinaryTestCase
{
    private Batch $rodeo;

    protected function setUp(): void
    {
        parent::setUp();

        $cria = Activity::updateOrCreate(['code' => 'CRIA'], ['name' => 'Cría']);
        $this->rodeo = Batch::create([
            'company_id' => $this->company->id,
            'name' => 'Rodeo Aptitud',
            'is_active' => true,
            'activity_id' => $cria->id,
        ]);
    }

    public function test_animals_are_eligible_by_default(): void
    {
        $this->assertTrue((bool) $this->animal('APT-DEF', 'H')->fresh()->is_breeding_eligible);
    }

    public function test_several_animals_are_excluded_at_once_and_each_change_is_logged(): void
    {
        $cows = [$this->animal('APT-V-01', 'H'), $this->animal('APT-V-02', 'H')];

        $response = $this->update(array_map(fn (Caravan $c) => $c->id, $cows), false, 'CUT_DENTITION', 'Boqueo de octubre');

        $response->assertStatus(200);
        $this->assertSame(2, $response->json('updated'));
        $this->assertSame([], $response->json('warnings'));

        foreach ($cows as $cow) {
            $fresh = $cow->fresh();
            $this->assertFalse((bool) $fresh->is_breeding_eligible);
            $this->assertSame('CUT_DENTITION', $fresh->breeding_exclusion_reason);
            $this->assertDatabaseHas('caravan_breeding_eligibility_changes', [
                'caravan_id' => $cow->id,
                'is_breeding_eligible' => false,
                'reason' => 'CUT_DENTITION',
                'note' => 'Boqueo de octubre',
                'changed_by_user_id' => $this->user->id,
            ]);
        }
    }

    public function test_re_enabling_clears_the_reason_and_keeps_the_history(): void
    {
        $bull = $this->animal('APT-T-01', 'M');
        $this->update([$bull->id], false, 'RESTING')->assertStatus(200);

        $this->update([$bull->id], true, 'RESTING')->assertStatus(200);

        $fresh = $bull->fresh();
        $this->assertTrue((bool) $fresh->is_breeding_eligible);
        $this->assertNull($fresh->breeding_exclusion_reason);
        $this->assertSame(2, CaravanBreedingEligibilityChange::withoutGlobalScopes()->where('caravan_id', $bull->id)->count());
    }

    public function test_a_reason_of_the_other_sex_is_rejected(): void
    {
        $bull = $this->animal('APT-T-02', 'M');

        $response = $this->update([$bull->id], false, 'CUT_DENTITION');

        $response->assertStatus(422);
        $this->assertStringContainsString('APT-T-02', (string) $response->json('message'));
        $this->assertTrue((bool) $bull->fresh()->is_breeding_eligible);
    }

    public function test_other_requires_a_note(): void
    {
        $cow = $this->animal('APT-V-03', 'H');

        $this->update([$cow->id], false, 'OTHER')->assertStatus(422);
        $this->update([$cow->id], false, 'OTHER', 'Ojo lastimado')->assertStatus(200);
    }

    public function test_the_reason_is_optional(): void
    {
        $cow = $this->animal('APT-V-04', 'H');

        $this->update([$cow->id], false)->assertStatus(200);

        $this->assertFalse((bool) $cow->fresh()->is_breeding_eligible);
        $this->assertNull($cow->fresh()->breeding_exclusion_reason);
    }

    public function test_an_animal_in_an_active_service_is_excluded_with_a_warning(): void
    {
        $cow = $this->animal('APT-V-05', 'H');
        $bull = Caravan::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('identification', 'ANTHONY-TORO-TEST-004')
            ->firstOrFail();
        $this->apiAs('POST', "/batches/{$this->rodeo->id}/start-service", [
            'male_caravan_ids' => [$bull->id],
            'planned_start_date' => '2026-11-01',
            'service_type' => 'single',
            'is_controlled_service' => false,
        ])->assertStatus(201);

        $response = $this->update([$bull->id, $cow->id], false, 'SCHEDULED_FOR_SALE');

        $response->assertStatus(200);
        $this->assertEqualsCanonicalizing(
            ['ANTHONY-TORO-TEST-004', 'APT-V-05'],
            array_column($response->json('warnings'), 'caravana')
        );
        // The decision is kept; the active order is left as it is.
        $this->assertFalse((bool) $bull->fresh()->is_breeding_eligible);
        $this->assertDatabaseHas('service_order_males', ['male_caravan_id' => $bull->id, 'status' => 'ACTIVE']);
    }

    public function test_the_list_exposes_the_decision(): void
    {
        $cow = $this->animal('APT-V-06', 'H');
        $this->update([$cow->id], false, 'IMMATURE')->assertStatus(200);

        $response = $this->apiAs('GET', '/caravans/summary?search=APT-V-06')->assertStatus(200);

        $row = collect($response->json('data'))->firstWhere('id', $cow->id);
        $this->assertNotNull($row);
        $this->assertFalse($row['is_breeding_eligible']);
        $this->assertSame('IMMATURE', $row['breeding_exclusion_reason']);
        $this->assertSame('Sin desarrollo / se retiene sin servicio', $row['breeding_exclusion_reason_label']);
    }

    private function animal(string $tag, string $sex): Caravan
    {
        return Caravan::create([
            'company_id' => $this->company->id,
            'batch_id' => $this->rodeo->id,
            'identification' => $tag,
            'sex' => $sex,
        ]);
    }

    /**
     * @param int[] $ids
     * @return \Illuminate\Testing\TestResponse
     */
    private function update(array $ids, bool $eligible, ?string $reason = null, ?string $note = null)
    {
        return $this->apiAs('POST', '/caravans/bulk-breeding-eligibility', array_filter([
            'caravan_ids' => $ids,
            'is_eligible' => $eligible,
            'reason' => $reason,
            'note' => $note,
        ], fn ($value) => $value !== null));
    }
}
