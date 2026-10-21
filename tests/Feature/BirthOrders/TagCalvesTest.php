<?php

declare(strict_types=1);

namespace Tests\Feature\BirthOrders;

use App\Models\Caravan;

/**
 * "Caravanear crías": calves born without a tag get it days later, from their batch.
 */
class TagCalvesTest extends BirthOrderTestCase
{
    public function test_untagged_calves_are_listed_counted_and_tagged(): void
    {
        $first = $this->untaggedCalf('TC-M1', 'M');
        $second = $this->untaggedCalf('TC-M2', 'H');

        $listed = $this->apiAs('GET', "/batches/{$this->breedingA->id}/untagged-calves")->assertOk();
        $this->assertSame(['Cría de TC-M1 ♂', 'Cría de TC-M2 ♀'], collect($listed->json('data'))->pluck('label')->sort()->values()->all());

        $batch = $this->apiAs('GET', "/batches/{$this->breedingA->id}")->assertOk();
        $this->assertSame(2, $batch->json('data.untagged_calf_count') ?? $batch->json('untagged_calf_count'));

        // A partial load: one calf now, the other one keeps waiting.
        $this->apiAs('POST', '/caravans/tag', [
            'tagged_at' => now()->toDateString(),
            'items' => [['caravan_id' => $first->id, 'identification' => 'TC-C1']],
        ])->assertOk()->assertJsonPath('data.0.identification', 'TC-C1')->assertJsonPath('data.0.is_tagged', true);

        $this->assertSame('TC-C1', $first->fresh()->identification);
        $this->assertSame(now()->toDateString(), $first->fresh()->tagged_at->format('Y-m-d'));
        $this->assertNull($second->fresh()->identification);
        $this->assertCount(1, $this->apiAs('GET', "/batches/{$this->breedingA->id}/untagged-calves")->json('data'));
    }

    public function test_every_row_is_checked_and_nothing_is_written_when_one_fails(): void
    {
        $first = $this->untaggedCalf('TC-E1', 'M');
        $second = $this->untaggedCalf('TC-E2', 'H');
        $third = $this->untaggedCalf('TC-E3', 'H');

        $response = $this->apiAs('POST', '/caravans/tag', [
            'tagged_at' => now()->toDateString(),
            'items' => [
                ['caravan_id' => $first->id, 'identification' => 'TC-E1'],   // the mother's tag: in use
                ['caravan_id' => $second->id, 'identification' => 'TC-X1'],
                ['caravan_id' => $third->id, 'identification' => 'tc-x1'],   // repeated in the load
            ],
        ])->assertStatus(422);

        $this->assertArrayHasKey('items.0.identification', $response->json('errors'));
        $this->assertArrayHasKey('items.2.identification', $response->json('errors'));
        $this->assertArrayNotHasKey('items.1.identification', $response->json('errors'));
        $this->assertSame(0, Caravan::whereIn('id', [$first->id, $second->id, $third->id])->whereNotNull('identification')->count());
    }

    public function test_a_tagged_calf_and_a_date_before_birth_are_refused(): void
    {
        $calf = $this->untaggedCalf('TC-D1', 'M');

        $this->apiAs('POST', '/caravans/tag', [
            'tagged_at' => now()->subDays(3)->toDateString(),
            'items' => [['caravan_id' => $calf->id, 'identification' => 'TC-D9']],
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['items.0.identification']]);

        $this->apiAs('POST', '/caravans/tag', [
            'tagged_at' => now()->toDateString(),
            'items' => [['caravan_id' => $calf->id, 'identification' => 'TC-D9']],
        ])->assertOk();

        $this->apiAs('POST', '/caravans/tag', [
            'tagged_at' => now()->toDateString(),
            'items' => [['caravan_id' => $calf->id, 'identification' => 'TC-D10']],
        ])->assertStatus(422);

        $this->assertSame('TC-D9', $calf->fresh()->identification);
    }

    public function test_an_untagged_calf_is_found_by_its_mother_tag_and_shown_with_its_label(): void
    {
        $calf = $this->untaggedCalf('TC-S1', 'H');

        $summary = $this->apiAs('GET', '/caravans/summary?search=TC-S1&per_page=50')->assertOk();
        $row = collect($summary->json('data'))->firstWhere('id', $calf->id);

        $this->assertNotNull($row);
        $this->assertNull($row['identification']);
        $this->assertSame('Cría de TC-S1 ♀', $row['label']);
        $this->assertFalse($row['is_tagged']);

        $this->apiAs('GET', "/caravans/{$calf->id}")->assertOk()->assertJsonPath('data.label', 'Cría de TC-S1 ♀');
    }

    private function untaggedCalf(string $motherTag, string $sex): Caravan
    {
        $mother = $this->pregnantFemale($motherTag);

        $response = $this->apiAs('POST', '/birth-orders/register', [
            'animals' => [['caravan_id' => $mother->id, 'outcome' => 'LIVE', 'calf_sex' => $sex, 'birth_date' => now()->toDateString()]],
        ])->assertStatus(201);

        return Caravan::findOrFail(collect($response->json('order.animals'))->firstWhere('caravan_id', $mother->id)['calf_caravan_id']);
    }
}
