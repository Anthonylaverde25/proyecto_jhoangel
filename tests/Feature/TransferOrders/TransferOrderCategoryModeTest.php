<?php

declare(strict_types=1);

namespace Tests\Feature\TransferOrders;

use App\Models\Activity;
use App\Models\AnimalCategory;
use App\Models\AnimalSubcategory;
use App\Models\Batch;
use App\Models\BatchType;
use App\Models\Caravan;
use App\Models\CaravanMovement;
use App\Models\TransferOrderAnimal;
use Tests\Feature\Veterinary\VeterinaryTestCase;

/**
 * The category mode of a transfer order: KEEP, DECLARED at the desk, or decided AT_CHUTE and
 * written in the C/S cell of the sheet, which accepts a category or a subcategory.
 */
class TransferOrderCategoryModeTest extends VeterinaryTestCase
{
    private Batch $source;
    private Batch $target;

    protected function setUp(): void
    {
        parent::setUp();

        $this->source = Batch::create([
            'company_id' => $this->company->id,
            'name' => 'Cría Origen CAT',
            'activity_id' => $this->activityId('CRIA'),
            'is_active' => true,
        ]);

        $this->target = Batch::create([
            'company_id' => $this->company->id,
            'name' => 'Recría Destino CAT',
            'activity_id' => $this->activityId('RECRIA'),
            'batch_type_id' => (int) BatchType::withoutGlobalScopes()->where('code', 'OPERATIONAL')->value('id'),
            'is_confined' => false,
            'is_active' => true,
        ]);
    }

    // ------------------------------------------------------------------ issuing

    public function test_an_order_without_mode_keeps_the_category(): void
    {
        $response = $this->emit([$this->calf('CAT-K1', 'M')], null);

        $response->assertStatus(201);
        $this->assertSame('KEEP', $response->json('category_mode'));
        $this->assertNull($response->json('animals.0.target_category_id'));
    }

    public function test_a_declared_order_stores_a_target_per_animal_and_labels_it(): void
    {
        $male = $this->calf('CAT-D1', 'M');
        $female = $this->calf('CAT-D2', 'H');

        $response = $this->emit([$male, $female], 'DECLARED', [
            $male->id => [$this->categoryId('NOVILLITO'), null],
            $female->id => [$this->categoryId('VAQUILLONA'), $this->subcategoryId('VAQUILLONA', 'REPOSICION')],
        ]);

        $response->assertStatus(201);
        $this->assertSame('DECLARED', $response->json('category_mode'));

        $labels = collect($response->json('animals'))->pluck('target_category_label', 'caravan_id');
        $this->assertSame('Novillito', $labels[$male->id]);
        $this->assertSame('Vaquillona / Reposición', $labels[$female->id]);
    }

    public function test_a_declared_target_of_the_other_sex_is_refused(): void
    {
        $female = $this->calf('CAT-D3', 'H');

        $this->emit([$female], 'DECLARED', [$female->id => [$this->categoryId('NOVILLITO'), null]])
            ->assertStatus(422)
            ->assertJsonPath('code', 'CATEGORY_TARGET_INVALID');
    }

    public function test_a_subcategory_of_another_category_is_refused(): void
    {
        $female = $this->calf('CAT-D4', 'H');

        $this->emit([$female], 'DECLARED', [
            $female->id => [$this->categoryId('VAQUILLONA'), $this->subcategoryId('VACA', 'PLANTEL')],
        ])->assertStatus(422)->assertJsonPath('code', 'CATEGORY_TARGET_INVALID');
    }

    public function test_declared_without_any_target_is_refused(): void
    {
        $this->emit([$this->calf('CAT-D5', 'M')], 'DECLARED')
            ->assertStatus(422)
            ->assertJsonPath('code', 'CATEGORY_TARGET_MISSING');
    }

    public function test_targets_left_over_in_another_mode_are_dropped(): void
    {
        $male = $this->calf('CAT-D6', 'M');

        $response = $this->emit([$male], 'AT_CHUTE', [$male->id => [$this->categoryId('NOVILLITO'), null]]);

        $response->assertStatus(201);
        $this->assertNull(TransferOrderAnimal::where('caravan_id', $male->id)->value('target_category_id'));
    }

    // ------------------------------------------------------------------ executing

    public function test_a_declared_order_executed_from_the_screen_reclassifies_with_subcategory(): void
    {
        $male = $this->calf('CAT-E1', 'M');
        $female = $this->calf('CAT-E2', 'H');
        $stays = $this->calf('CAT-E3', 'M');

        $orderId = $this->emit([$male, $female, $stays], 'DECLARED', [
            $male->id => [$this->categoryId('NOVILLITO'), null],
            $female->id => [$this->categoryId('VAQUILLONA'), $this->subcategoryId('VAQUILLONA', 'REPOSICION')],
        ])->json('id');

        $this->apiAs('POST', "/transfer-orders/{$orderId}/execute", ['movement_date' => now()->toDateString()])
            ->assertSuccessful();

        $this->assertSame($this->categoryId('NOVILLITO'), (int) $male->fresh()->category_id);
        $this->assertSame($this->categoryId('VAQUILLONA'), (int) $female->fresh()->category_id);
        $this->assertSame($this->subcategoryId('VAQUILLONA', 'REPOSICION'), (int) $female->fresh()->subcategory_id);
        $this->assertSame($this->categoryId('TERNERO'), (int) $stays->fresh()->category_id);
        $this->assertStringContainsString(
            'Categoría: Ternero → Vaquillona / Reposición.',
            (string) CaravanMovement::where('caravan_id', $female->id)->value('observations')
        );
    }

    public function test_at_chute_resolves_a_subcategory_written_alone(): void
    {
        $female = $this->calf('CAT-S1', 'H');
        $orderId = $this->emit([$female], 'AT_CHUTE')->json('id');

        $this->scan($orderId, [[$female, 'Reposición']])->assertSuccessful();

        $this->assertSame($this->categoryId('VAQUILLONA'), (int) $female->fresh()->category_id);
        $this->assertSame($this->subcategoryId('VAQUILLONA', 'REPOSICION'), (int) $female->fresh()->subcategory_id);
    }

    public function test_at_chute_a_blank_cell_keeps_the_category(): void
    {
        $male = $this->calf('CAT-S2', 'M');
        $orderId = $this->emit([$male], 'AT_CHUTE')->json('id');

        $this->scan($orderId, [[$male, '—']])->assertSuccessful();

        $this->assertSame($this->categoryId('TERNERO'), (int) $male->fresh()->category_id);
    }

    public function test_at_chute_ambiguous_unknown_and_wrong_sex_are_row_errors_and_nothing_moves(): void
    {
        $female = $this->calf('CAT-S3', 'H');
        $other = $this->calf('CAT-S4', 'H');
        $male = $this->calf('CAT-S5', 'M');
        $orderId = $this->emit([$female, $other, $male], 'AT_CHUTE')->json('id');

        $response = $this->scan($orderId, [[$female, 'Descarte'], [$other, 'Búfalo'], [$male, 'Reposición']]);

        $response->assertStatus(422);
        $codes = collect($response->json('row_errors'))->pluck('errors.*.code')->flatten()->all();
        $this->assertContains('CATEGORY_TEXT_AMBIGUOUS', $codes);
        $this->assertContains('CATEGORY_TEXT_NOT_FOUND', $codes);
        $this->assertContains('CATEGORY_SEX_MISMATCH', $codes);
        $this->assertSame($this->source->id, (int) $female->fresh()->batch_id);
    }

    public function test_keep_ignores_a_written_category_and_warns(): void
    {
        $male = $this->calf('CAT-S6', 'M');
        $orderId = $this->emit([$male], null)->json('id');

        $response = $this->scan($orderId, [[$male, 'Novillito']]);

        $response->assertSuccessful();
        $this->assertContains('CATEGORY_CHANGE_NOT_EXPECTED', array_column($response->json('data.warnings'), 'code'));
        $this->assertSame($this->categoryId('TERNERO'), (int) $male->fresh()->category_id);
    }

    public function test_declared_what_the_chute_wrote_wins_and_is_reported(): void
    {
        $male = $this->calf('CAT-S7', 'M');
        $printed = $this->calf('CAT-S8', 'M');
        $orderId = $this->emit([$male, $printed], 'DECLARED', [
            $male->id => [$this->categoryId('NOVILLITO'), null],
            $printed->id => [$this->categoryId('NOVILLITO'), null],
        ])->json('id');

        // One cell overwritten at the chute, the other unreadable: the order's target applies.
        $response = $this->scan($orderId, [[$male, 'Torito'], [$printed, null]]);

        $response->assertSuccessful();
        $this->assertContains('CATEGORY_DIFFERS_FROM_ORDER', array_column($response->json('data.warnings'), 'code'));
        $this->assertSame($this->categoryId('TORITO'), (int) $male->fresh()->category_id);
        $this->assertSame($this->categoryId('NOVILLITO'), (int) $printed->fresh()->category_id);
    }

    public function test_the_same_category_written_again_keeps_the_subcategory(): void
    {
        $female = $this->calf('CAT-S9', 'H');
        $female->update([
            'category_id' => $this->categoryId('VAQUILLONA'),
            'subcategory_id' => $this->subcategoryId('VAQUILLONA', 'REPOSICION'),
        ]);
        $orderId = $this->emit([$female], 'AT_CHUTE')->json('id');

        $this->scan($orderId, [[$female, 'Vaquillona']])->assertSuccessful();

        $this->assertSame($this->subcategoryId('VAQUILLONA', 'REPOSICION'), (int) $female->fresh()->subcategory_id);
    }

    public function test_a_change_of_subcategory_alone_is_a_change(): void
    {
        $female = $this->calf('CAT-S10', 'H');
        $female->update([
            'category_id' => $this->categoryId('VAQUILLONA'),
            'subcategory_id' => $this->subcategoryId('VAQUILLONA', 'REPOSICION'),
        ]);
        $orderId = $this->emit([$female], 'AT_CHUTE')->json('id');

        $this->scan($orderId, [[$female, 'Vaquillona / Descarte']])->assertSuccessful();

        $this->assertSame($this->subcategoryId('VAQUILLONA', 'DESCARTE_FAENA'), (int) $female->fresh()->subcategory_id);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @param list<Caravan> $animals
     * @param array<int, array{0: int, 1: ?int}> $targets caravan id => [category id, subcategory id]
     */
    private function emit(array $animals, ?string $mode, array $targets = [])
    {
        $payload = [
            'issue' => true,
            'source_batch_id' => $this->source->id,
            'destination_activity_id' => $this->activityId('RECRIA'),
            'destination_mode' => 'single',
            'movement_date' => now()->toDateString(),
            'destinations' => [['key' => 'dest-single', 'label' => '', 'target_batch_id' => $this->target->id]],
            'animals' => array_map(fn (Caravan $c) => [
                'caravan_id' => $c->id,
                'destination_key' => 'dest-single',
                'target_category_id' => $targets[$c->id][0] ?? null,
                'target_subcategory_id' => $targets[$c->id][1] ?? null,
            ], $animals),
        ];

        if ($mode !== null) {
            $payload['category_mode'] = $mode;
        }

        return $this->apiAs('POST', '/transfer-orders', $payload);
    }

    /**
     * @param list<array{0: Caravan, 1: ?string}> $lines animal and what its C/S cell says
     */
    private function scan(int $orderId, array $lines)
    {
        return $this->apiAs('POST', '/work-templates/cact-01/process', [
            'source_batch_id' => $this->source->id,
            'fecha_movimiento' => now()->toDateString(),
            'actividad_destino_id' => $this->activityId('RECRIA'),
            'transfer_order_id' => $orderId,
            'destinations' => [['key' => 'DESTINO', 'target_batch_id' => $this->target->id, 'new_batch' => null]],
            'rows' => array_map(fn (array $line) => [
                'caravana' => $line[0]->identification,
                'peso_actual' => null,
                'dientes' => null,
                'destination_key' => 'DESTINO',
                'cs_nueva' => $line[1],
            ], $lines),
        ]);
    }

    private function calf(string $tag, string $sex): Caravan
    {
        return Caravan::create([
            'company_id' => $this->company->id,
            'batch_id' => $this->source->id,
            'identification' => $tag,
            'sex' => $sex,
            'teeth' => 0,
            'category_id' => $this->categoryId('TERNERO'),
        ]);
    }

    private function categoryId(string $code): int
    {
        return (int) AnimalCategory::where('code', $code)->value('id');
    }

    private function subcategoryId(string $categoryCode, string $code): int
    {
        return (int) AnimalSubcategory::where('category_id', $this->categoryId($categoryCode))->where('code', $code)->value('id');
    }

    private function activityId(string $code): int
    {
        return (int) Activity::withoutGlobalScopes()->where('code', $code)->value('id');
    }
}
