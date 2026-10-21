<?php

declare(strict_types=1);

namespace Tests\Feature\BirthOrders;

use App\Models\Batch;
use App\Models\BatchType;
use App\Models\Caravan;
use App\Models\CaravanDeath;
use App\Models\CaravanGestation;
use App\Models\CaravanLineage;
use App\Models\CaravanMovement;
use App\Models\DeathCause;
use App\Models\GestationLossReason;

/**
 * "Registrar muerte": a declared fact (date and cause) that moves the animal to a death batch,
 * for an animal with or without its tag.
 */
class RegisterDeathTest extends BirthOrderTestCase
{
    public function test_an_untagged_calf_dies_and_leaves_the_living_herd(): void
    {
        $calf = $this->untaggedCalf('RD-M1');
        $cause = $this->cause('DIARRHEA');

        $response = $this->die([$calf], $cause)->assertStatus(201)
            ->assertJsonPath('data.0.is_dead', true)
            ->assertJsonPath('data.0.label', 'Cría de RD-M1 ♀')
            ->assertJsonPath('data.0.death.cause', 'Diarrea');

        // With no death batch, one is created; the calf goes there with a DEATH movement.
        $deathBatch = Batch::where('name', 'Lote de Muertes')->firstOrFail();
        $this->assertSame($deathBatch->id, (int) $calf->fresh()->batch_id);
        $this->assertSame($deathBatch->id, $response->json('data.0.batch.id'));
        $this->assertTrue(CaravanMovement::where('caravan_id', $calf->id)->where('type', 'DEATH')->exists());
        $this->assertFalse((bool) CaravanLineage::where('caravan_id', $calf->id)->value('is_nursing'));

        $death = CaravanDeath::where('caravan_id', $calf->id)->firstOrFail();
        $this->assertSame($this->breedingA->id, $death->from_batch_id);
        $this->assertSame(now()->toDateString(), $death->death_date->format('Y-m-d'));

        // A calf that died is not tagged, and is not counted as waiting for its tag.
        $this->assertCount(0, $this->apiAs('GET', "/batches/{$this->breedingA->id}/untagged-calves")->json('data'));
        $this->assertCount(0, $this->apiAs('GET', "/batches/{$deathBatch->id}/untagged-calves")->json('data'));
        $this->apiAs('POST', '/caravans/tag', [
            'tagged_at' => now()->toDateString(),
            'items' => [['caravan_id' => $calf->id, 'identification' => 'RD-C1']],
        ])->assertStatus(422);
    }

    public function test_a_pregnant_female_dies_and_her_gestation_is_lost(): void
    {
        $female = $this->pregnantFemale('RD-P1');

        $this->die([$female], $this->cause('ACCIDENT'))->assertStatus(201);

        $gestation = CaravanGestation::where('caravan_id', $female->id)->latest('id')->firstOrFail();
        $this->assertFalse((bool) $gestation->is_current);
        $this->assertFalse((bool) $gestation->success);
        $this->assertSame('MOTHER_DEATH', GestationLossReason::whereKey($gestation->loss_reason_id)->value('code'));
    }

    public function test_every_animal_is_checked_and_nothing_is_written_when_one_fails(): void
    {
        $held = $this->pregnantFemale('RD-H1');
        $this->emit([$held]);
        $free = $this->pregnantFemale('RD-H2');

        $response = $this->die([$free, $held], $this->cause('UNKNOWN'))->assertStatus(422);

        $this->assertArrayHasKey('caravan_ids.1', $response->json('errors'));
        $this->assertArrayNotHasKey('caravan_ids.0', $response->json('errors'));
        $this->assertSame(0, CaravanDeath::count());
        $this->assertSame($this->breedingA->id, (int) $free->fresh()->batch_id);
    }

    public function test_an_animal_dies_once_and_never_before_its_birth_or_in_the_future(): void
    {
        $calf = $this->untaggedCalf('RD-D1');
        $cause = $this->cause('PNEUMONIA');

        $this->die([$calf], $cause, now()->subDays(2)->toDateString())->assertStatus(422)
            ->assertJsonStructure(['errors' => ['caravan_ids.0']]);
        $this->die([$calf], $cause, now()->addDay()->toDateString())->assertStatus(422);

        $this->die([$calf], $cause)->assertStatus(201);
        $this->die([$calf], $cause)->assertStatus(422)->assertJsonStructure(['errors' => ['caravan_ids.0']]);
    }

    public function test_the_death_batch_is_the_only_one_or_the_chosen_one(): void
    {
        $first = $this->deathBatch('Muertes Norte');
        $calf = $this->untaggedCalf('RD-B1');
        $cause = $this->cause('PREDATOR');

        $this->die([$calf], $cause)->assertStatus(201);
        $this->assertSame($first->id, (int) $calf->fresh()->batch_id);

        $second = $this->deathBatch('Muertes Sur');
        $other = $this->untaggedCalf('RD-B2');

        $this->die([$other], $cause)->assertStatus(422)->assertJsonStructure(['errors' => ['death_batch_id']]);
        $this->die([$other], $cause, null, $second->id)->assertStatus(201);
        $this->assertSame($second->id, (int) $other->fresh()->batch_id);

        $this->assertCount(2, $this->apiAs('GET', '/death-batches')->json('data'));
        $this->assertNotEmpty($this->apiAs('GET', '/death-causes')->json('data'));
    }

    public function test_the_quick_transfer_does_not_take_deaths_nor_untagged_calves(): void
    {
        $deathBatch = $this->deathBatch('Muertes Rápido');
        $cow = $this->pregnantFemale('RD-T1');
        $calf = $this->untaggedCalf('RD-T2');

        $this->apiAs('POST', '/caravans/bulk-transfer', ['caravan_ids' => [$cow->id], 'target_batch_id' => $deathBatch->id])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Para dar de baja por muerte usá «Registrar muerte»: guarda la fecha y la causa.');
        $this->apiAs('POST', '/caravans/bulk-transfer', ['caravan_ids' => [$calf->id], 'target_batch_id' => $this->breedingB->id])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Sin caravana: Cría de RD-T2 ♀. Caravanealas antes de trasladarlas.');

        $this->assertSame($this->breedingA->id, (int) $cow->fresh()->batch_id);
        $this->assertSame($this->breedingA->id, (int) $calf->fresh()->batch_id);
    }

    public function test_a_dead_animal_is_not_weighed_after_its_death(): void
    {
        $female = $this->pregnantFemale('RD-W1');
        $this->die([$female], $this->cause('BLOAT'), now()->subDay()->toDateString())->assertStatus(201);

        $this->apiAs('POST', "/caravans/{$female->id}/weights", ['weight' => 420, 'weighing_date' => now()->toDateString()])
            ->assertStatus(422)
            ->assertJsonPath('code', 'ALREADY_DEAD');
    }

    /**
     * @param Caravan[] $animals
     */
    private function die(array $animals, DeathCause $cause, ?string $date = null, ?int $deathBatchId = null)
    {
        return $this->apiAs('POST', '/caravans/deaths', array_filter([
            'caravan_ids' => array_map(fn (Caravan $c) => $c->id, $animals),
            'death_date' => $date ?? now()->toDateString(),
            'death_cause_id' => $cause->id,
            'observations' => 'Encontrado en la recorrida',
            'death_batch_id' => $deathBatchId,
        ], fn ($value) => $value !== null));
    }

    private function cause(string $code): DeathCause
    {
        return DeathCause::where('company_id', $this->company->id)->where('code', $code)->firstOrFail();
    }

    private function deathBatch(string $name): Batch
    {
        return Batch::create([
            'company_id' => $this->company->id,
            'name' => $name,
            'activity_id' => $this->activityId('CRIA'),
            'batch_type_id' => (int) BatchType::withoutGlobalScopes()->where('code', 'INTERNAL_DEATH')->value('id'),
            'is_active' => true,
        ]);
    }

    private function untaggedCalf(string $motherTag): Caravan
    {
        $mother = $this->pregnantFemale($motherTag);

        $response = $this->apiAs('POST', '/birth-orders/register', [
            'animals' => [['caravan_id' => $mother->id, 'outcome' => 'LIVE', 'calf_sex' => 'H', 'birth_date' => now()->toDateString()]],
        ])->assertStatus(201);

        return Caravan::findOrFail(collect($response->json('order.animals'))->firstWhere('caravan_id', $mother->id)['calf_caravan_id']);
    }
}
