<?php

declare(strict_types=1);

namespace Tests\Feature\EntryOrders;

use App\Models\Caravan;
use App\Models\EntryOrderAnimal;

/**
 * A purchase may bring several categories, each with its head. The order's head is their sum, the
 * declared sexes have to fit them, and each received animal takes the only category its sex admits
 * or, when its sex admits several, the one its line declares (CAT).
 */
class EntryOrderCategoriesTest extends EntryOrderTestCase
{
    /**
     * 6 Novillito + 4 Torito + 5 Vaquillona: 10 males, 5 females, one breed.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function severalCategories(array $overrides = []): array
    {
        return [
            'categories' => [
                ['category_id' => $this->categoryId('NOVILLITO'), 'head_count' => 6],
                ['category_id' => $this->categoryId('TORITO'), 'head_count' => 4],
                ['category_id' => $this->categoryId('VAQUILLONA'), 'head_count' => 5],
            ],
            'sex_composition' => 'MIXED',
            'male_count' => 10,
            'female_count' => 5,
            'breeds' => [['breed_id' => $this->breedId('Braford'), 'color_id' => $this->colorId('Colorado')]],
            ...$overrides,
        ];
    }

    public function test_the_head_of_the_order_is_the_sum_of_its_categories(): void
    {
        $order = $this->createOrder($this->severalCategories())->assertCreated()->json('order');

        $this->assertSame(15, $order['head_count']);
        $this->assertCount(3, $order['categories']);
        $this->assertSame([1, 2, 3], array_column($order['categories'], 'position'));
        $this->assertSame([6, 4, 5], array_column($order['categories'], 'head_count'));
        $this->assertSame(['M', 'M', 'H'], array_column($order['categories'], 'sex'));
        $this->assertSame('6 Novillito', $order['categories'][0]['label']);
        // A male may be a Novillito or a Torito: each line has to say which.
        $this->assertTrue($order['needs_category_per_animal']);
    }

    public function test_categories_of_different_sexes_need_no_category_per_animal(): void
    {
        $order = $this->createOrder($this->severalCategories([
            'categories' => [
                ['category_id' => $this->categoryId('NOVILLITO'), 'head_count' => 10],
                ['category_id' => $this->categoryId('VAQUILLONA'), 'head_count' => 5],
            ],
        ]))->assertCreated()->json('order');

        $this->assertFalse($order['needs_category_per_animal']);
    }

    public function test_the_sexes_have_to_fit_the_categories(): void
    {
        // 6 Novillito + 4 Torito are 10 males at least.
        $this->createOrder($this->severalCategories(['male_count' => 9, 'female_count' => 6]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'SEX_COUNTS_CONTRADICT_CATEGORIES')
            ->assertJsonPath('field', 'male_count');

        // A troop of males cannot hold heifers.
        $this->createOrder($this->severalCategories(['sex_composition' => 'MALE', 'male_count' => null, 'female_count' => null]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'SEX_NOT_ALLOWED_BY_CATEGORY');

        // Both sexes need a category that admits females.
        $this->createOrder($this->severalCategories([
            'categories' => [
                ['category_id' => $this->categoryId('NOVILLITO'), 'head_count' => 10],
                ['category_id' => $this->categoryId('TORITO'), 'head_count' => 5],
            ],
        ]))->assertStatus(422)->assertJsonPath('code', 'SEX_NOT_ALLOWED_BY_CATEGORY');
    }

    public function test_a_category_is_declared_once(): void
    {
        $this->createOrder($this->severalCategories([
            'categories' => [
                ['category_id' => $this->categoryId('NOVILLITO'), 'head_count' => 6],
                ['category_id' => $this->categoryId('NOVILLITO'), 'head_count' => 4],
                ['category_id' => $this->categoryId('VAQUILLONA'), 'head_count' => 5],
            ],
        ]))->assertStatus(422)->assertJsonPath('code', 'CATEGORY_DUPLICATED');
    }

    public function test_a_draft_may_leave_the_head_of_a_category_out_but_not_to_confirm(): void
    {
        $draft = $this->createOrder($this->severalCategories([
            'categories' => [['category_id' => $this->categoryId('NOVILLITO'), 'head_count' => null]],
            'sex_composition' => 'MALE',
            'male_count' => null,
            'female_count' => null,
        ]), false)->assertCreated()->json('order');

        $this->assertNull($draft['head_count']);
        $this->assertNull($draft['categories'][0]['head_count']);

        $this->apiAs('POST', "/entry-orders/{$draft['id']}/confirm")
            ->assertStatus(422)
            ->assertJsonPath('code', 'TROOP_INCOMPLETE')
            ->assertJsonPath('field', 'categories');

        // Rewriting the draft replaces its categories whole.
        $updated = $this->apiAs('PUT', "/entry-orders/{$draft['id']}", [...$this->troop($this->severalCategories()), 'confirm' => false])
            ->assertOk()
            ->json('order');

        $this->assertSame(15, $updated['head_count']);
        $this->assertCount(3, $updated['categories']);
    }

    public function test_the_sex_tells_the_category_when_it_admits_only_one(): void
    {
        $order = $this->createOrder($this->severalCategories([
            'categories' => [
                ['category_id' => $this->categoryId('NOVILLITO'), 'head_count' => 2],
                ['category_id' => $this->categoryId('VAQUILLONA'), 'head_count' => 1],
            ],
            'male_count' => 2,
            'female_count' => 1,
        ]))->json('order');
        $loaded = $this->loadDte($order['id'], 3);

        $received = $this->receiveOn($loaded, [
            ['caravana' => 'EO-C-1', 'sex' => 'M'],
            ['caravana' => 'EO-C-2', 'sex' => 'M'],
            ['caravana' => 'EO-C-3', 'sex' => 'H'],
        ]);

        $this->assertSame($this->categoryId('NOVILLITO'), (int) Caravan::where('identification', 'EO-C-1')->value('category_id'));
        $this->assertSame($this->categoryId('VAQUILLONA'), (int) Caravan::where('identification', 'EO-C-3')->value('category_id'));
        $this->assertSame([2, 1], array_column($received['received_by_category'], 'received_count'));
        $this->assertSame(1, $received['dtes'][0]['animals'][0]['category_position']);
    }

    public function test_when_the_sex_admits_several_categories_the_line_says_which(): void
    {
        $order = $this->createOrder($this->severalCategories())->json('order');
        $loaded = $this->loadDte($order['id'], 15);

        $response = $this->receive($order['id'], ['dte_id' => $loaded['dtes'][0]['id'], 'animals' => [
            ['caravana' => 'EO-C-1', 'sex' => 'M'],
            ['caravana' => 'EO-C-2', 'sex' => 'H', 'category_position' => 1],
            ['caravana' => 'EO-C-3', 'sex' => 'M', 'category_position' => 9],
        ]])->assertStatus(422);

        $errors = collect($response->json('row_errors'))->keyBy('row');
        $this->assertSame('CATEGORY_MISSING', $errors[0]['code']);
        $this->assertSame('category_position', $errors[0]['field']);
        $this->assertStringContainsString('1 Novillito, 2 Torito', $errors[0]['message']);
        $this->assertSame('CATEGORY_CONTRADICTS_SEX', $errors[1]['code']);
        $this->assertSame('CATEGORY_UNKNOWN', $errors[2]['code']);

        $received = $this->receiveOn($loaded, [
            ['caravana' => 'EO-C-1', 'sex' => 'M', 'category_position' => 2],
            ['caravana' => 'EO-C-2', 'sex' => 'H'],
        ]);

        $torito = Caravan::where('identification', 'EO-C-1')->firstOrFail();
        $this->assertSame($this->categoryId('TORITO'), (int) $torito->category_id);
        $this->assertSame($this->categoryId('VAQUILLONA'), (int) Caravan::where('identification', 'EO-C-2')->value('category_id'));
        $this->assertNotNull(EntryOrderAnimal::where('caravan_id', $torito->id)->value('entry_order_category_id'));
        $this->assertSame([0, 1, 1], array_column($received['received_by_category'], 'received_count'));
    }
}
