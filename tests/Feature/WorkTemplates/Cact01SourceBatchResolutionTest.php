<?php

declare(strict_types=1);

namespace Tests\Feature\WorkTemplates;

use App\Models\Activity;
use App\Models\AnimalCategory;
use App\Models\Batch;
use App\Models\Caravan;
use Tests\Feature\Veterinary\VeterinaryTestCase;

/**
 * CACT-01: the source batch proposed on the review screen, from the name written on the
 * sheet and from where the animals it lists are today.
 */
class Cact01SourceBatchResolutionTest extends VeterinaryTestCase
{
    private Batch $cria;
    private Batch $recria;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cria = $this->batch('Test Cact Cría');
        $this->recria = $this->batch('Test Cact Recría');
    }

    public function test_name_and_animals_agree(): void
    {
        $this->animals($this->cria, ['SB-01', 'SB-02', 'SB-03']);

        $data = $this->resolve('TEST CACT CRIA', ['SB-01', 'SB-02', 'SB-03']);

        $this->assertSame('name_and_caravans', $data['basis']);
        $this->assertSame($this->cria->id, $data['proposed']['id']);
        $this->assertSame(3, $data['caravans_in_match']);
    }

    public function test_a_misread_name_is_resolved_by_the_animals(): void
    {
        $tags = [];
        for ($i = 1; $i <= 14; $i++) {
            $tags[] = sprintf('SB-%02d', $i);
        }
        $this->animals($this->cria, $tags);

        // One tag misread too: it does not exist, and must not stop the proposal.
        $data = $this->resolve('TEST CACT CRIO', [...$tags, 'SB-99']);

        $this->assertSame('caravans', $data['basis']);
        $this->assertSame($this->cria->id, $data['proposed']['id']);
        $this->assertNull($data['name_match']);
        $this->assertSame(15, $data['caravans_read']);
        $this->assertSame(14, $data['caravans_in_match']);
        $this->assertSame(1, $data['caravans_not_found']);
    }

    public function test_name_and_animals_pointing_to_different_batches_is_a_conflict(): void
    {
        $this->animals($this->recria, ['SB-01', 'SB-02', 'SB-03']);

        $data = $this->resolve('Test Cact Cría', ['SB-01', 'SB-02', 'SB-03']);

        $this->assertSame('conflict', $data['basis']);
        $this->assertNull($data['proposed'], 'En conflicto no se elige solo');
        $this->assertSame($this->cria->id, $data['name_match']['id']);
        $this->assertSame($this->recria->id, $data['caravans_match']['id']);
    }

    public function test_a_troop_split_evenly_proposes_nothing(): void
    {
        $this->animals($this->cria, ['SB-01', 'SB-02']);
        $this->animals($this->recria, ['SB-03', 'SB-04']);

        $data = $this->resolve('ILEGIBLE', ['SB-01', 'SB-02', 'SB-03', 'SB-04']);

        $this->assertSame('none', $data['basis']);
        $this->assertNull($data['proposed']);
        $this->assertCount(2, $data['distribution']);
    }

    public function test_unknown_animals_fall_back_to_the_name(): void
    {
        $this->assertSame('name', $this->resolve('Test Cact Cría', ['NADA-1', 'NADA-2'])['basis']);
        $this->assertSame('none', $this->resolve('ILEGIBLE', ['NADA-1', 'NADA-2'])['basis']);
    }

    public function test_animals_in_an_inactive_batch_do_not_vote(): void
    {
        $closed = $this->batch('Test Cact Cerrado', active: false);
        $this->animals($closed, ['SB-01', 'SB-02', 'SB-03']);
        $this->animals($this->cria, ['SB-04']);

        $data = $this->resolve('ILEGIBLE', ['SB-01', 'SB-02', 'SB-03', 'SB-04']);

        $this->assertSame('caravans', $data['basis']);
        $this->assertSame($this->cria->id, $data['proposed']['id']);
    }

    public function test_the_name_matches_regardless_of_accents_and_spacing(): void
    {
        $data = $this->resolve('  test   cact  recria ', []);

        $this->assertSame('name', $data['basis']);
        $this->assertSame($this->recria->id, $data['proposed']['id']);
    }

    public function test_it_says_what_the_system_knows_of_each_animal_found(): void
    {
        $ternero = (int) AnimalCategory::firstOrCreate(['code' => 'TERNERO'], ['name' => 'Ternero', 'sex' => 'M'])->id;
        $this->animals($this->cria, ['SB-01']);
        Caravan::where('identification', 'SB-01')->update(['category_id' => $ternero]);

        $data = $this->resolve('Test Cact Cría', ['sb-01', 'NADA-1']);

        $this->assertSame(
            [['identification' => 'SB-01', 'sex' => 'M', 'category_label' => 'Ternero']],
            $data['animals']
        );
    }

    /**
     * @param string[] $tags
     * @return array<string, mixed>
     */
    private function resolve(string $writtenName, array $tags): array
    {
        $response = $this->apiAs('POST', '/work-templates/cact-01/source-batch', [
            'lote_origen' => $writtenName,
            'caravanas' => $tags,
        ]);

        $response->assertOk();

        return $response->json('data');
    }

    private function batch(string $name, bool $active = true): Batch
    {
        return Batch::create([
            'company_id' => $this->company->id,
            'name' => $name,
            'activity_id' => (int) Activity::withoutGlobalScopes()->where('code', 'CRIA')->value('id'),
            'is_active' => $active,
        ]);
    }

    /**
     * @param string[] $tags
     */
    private function animals(Batch $batch, array $tags): void
    {
        foreach ($tags as $tag) {
            Caravan::create([
                'company_id' => $this->company->id,
                'batch_id' => $batch->id,
                'identification' => $tag,
                'sex' => 'M',
            ]);
        }
    }
}
