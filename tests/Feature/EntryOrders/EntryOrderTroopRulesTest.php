<?php

declare(strict_types=1);

namespace Tests\Feature\EntryOrders;

use App\Models\Batch;

/**
 * What a purchase can declare: sex against the category, the sexes adding up to the head, breeds
 * with their own coats, a coherent age and weights, and a batch name that does not clash.
 */
class EntryOrderTroopRulesTest extends EntryOrderTestCase
{
    public function test_a_heifer_category_cannot_hold_males(): void
    {
        $this->createOrder([
            'category_id' => $this->categoryId('VAQUILLONA'),
            'sex_composition' => 'MALE',
            'male_count' => null,
            'female_count' => null,
        ])->assertStatus(422)->assertJsonPath('code', 'SEX_NOT_ALLOWED_BY_CATEGORY');

        $this->createOrder([
            'category_id' => $this->categoryId('VAQUILLONA'),
            'sex_composition' => 'FEMALE',
            'male_count' => null,
            'female_count' => null,
        ])->assertCreated();
    }

    public function test_both_sexes_must_add_up_to_the_head(): void
    {
        $this->createOrder(['male_count' => 20, 'female_count' => 15])
            ->assertStatus(422)
            ->assertJsonPath('code', 'SEX_COUNTS_MISMATCH');

        $this->createOrder(['male_count' => null, 'female_count' => null])
            ->assertStatus(422)
            ->assertJsonPath('code', 'SEX_COUNTS_MISSING');
    }

    public function test_a_coat_must_belong_to_its_breed_and_pairs_do_not_repeat(): void
    {
        // Hereford is seeded with Pampa only.
        $this->createOrder(['breeds' => [['breed_id' => $this->breedId('Hereford'), 'color_id' => $this->colorId('Negro')]]])
            ->assertStatus(422)
            ->assertJsonPath('code', 'COLOR_NOT_OF_BREED');

        $braford = ['breed_id' => $this->breedId('Braford'), 'color_id' => $this->colorId('Colorado')];
        $this->createOrder(['breeds' => [$braford, $braford]])
            ->assertStatus(422)
            ->assertJsonPath('code', 'BREED_DUPLICATED');

        // The same breed with two coats is two lines.
        $this->createOrder(['breeds' => [
            ['breed_id' => $this->breedId('Angus'), 'color_id' => $this->colorId('Negro')],
            ['breed_id' => $this->breedId('Angus'), 'color_id' => $this->colorId('Colorado')],
        ]])->assertCreated();
    }

    public function test_age_range_and_weights_are_coherent(): void
    {
        $this->createOrder(['age_min_months' => 10, 'age_max_months' => 9])
            ->assertStatus(422)->assertJsonPath('code', 'AGE_RANGE_INVERTED');
        $this->createOrder(['age_max_months' => null])
            ->assertStatus(422)->assertJsonPath('code', 'AGE_RANGE_INCOMPLETE');
        $this->createOrder(['min_weight' => 190])
            ->assertStatus(422)->assertJsonPath('code', 'WEIGHT_RANGE_INVALID');
        $this->createOrder(['age_min_months' => null, 'age_max_months' => null, 'min_weight' => null, 'max_weight' => null, 'shrink_percent' => null])
            ->assertCreated();
    }

    public function test_sabe_comer_and_garrapata_must_be_answered(): void
    {
        $this->createOrder(['knows_to_eat' => null])->assertStatus(422)->assertJsonValidationErrors('knows_to_eat');
        $this->createOrder(['tick_vaccinated' => null])->assertStatus(422)->assertJsonValidationErrors('tick_vaccinated');
    }

    public function test_the_farm_must_belong_to_the_provider(): void
    {
        $foreignFarm = \App\Models\Farm::create([
            'company_id' => $this->company->id,
            'provider_id' => \App\Models\Provider::create(['name' => 'Otro EO', 'cuit' => '30-70000009-9', 'is_active' => true])->id,
            'name' => 'Campo ajeno EO',
            'renspa' => 'NO_DEFINIDO',
            'is_active' => true,
        ]);

        $this->createOrder(['farm_id' => $foreignFarm->id])
            ->assertStatus(422)
            ->assertJsonPath('code', 'FARM_NOT_OF_PROVIDER');
    }

    public function test_without_auction_the_user_writes_the_name(): void
    {
        $this->createOrder(['auction_number' => null])
            ->assertStatus(422)
            ->assertJsonPath('code', 'BATCH_NAME_NEEDS_AUCTION');

        $order = $this->createOrder(['auction_number' => null, 'batch_name_mode' => 'CUSTOM', 'batch_name' => 'Compra Directa Pérez'])
            ->assertCreated()
            ->json('order');

        $this->assertSame('Compra Directa Pérez', $order['batch_name']);
        $this->assertSame('CUSTOM', $order['batch_name_mode']);
    }

    public function test_a_name_in_use_in_the_same_farm_blocks_and_elsewhere_only_warns(): void
    {
        Batch::create(['company_id' => $this->company->id, 'name' => 'Tropa EO', 'farm_id' => $this->farm->id, 'is_active' => true]);
        Batch::create(['company_id' => $this->company->id, 'name' => 'Tropa Dos EO', 'farm_id' => $this->otherFarm->id, 'is_active' => true]);

        $this->createOrder(['batch_name_mode' => 'CUSTOM', 'batch_name' => 'Tropa EO'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'BATCH_NAME_IN_USE');

        $response = $this->createOrder(['batch_name_mode' => 'CUSTOM', 'batch_name' => 'Tropa Dos EO'])->assertCreated();
        $this->assertSame('BATCH_NAME_SHARED', $response->json('warnings.0.code'));
    }

    public function test_a_draft_with_a_clashing_name_is_saved_with_a_warning_and_blocked_on_confirm(): void
    {
        Batch::create(['company_id' => $this->company->id, 'name' => 'Tropa EO', 'farm_id' => $this->farm->id, 'is_active' => true]);

        $response = $this->createOrder(['batch_name_mode' => 'CUSTOM', 'batch_name' => 'Tropa EO'], false)->assertCreated();
        $this->assertSame('BATCH_NAME_IN_USE', $response->json('warnings.0.code'));

        $this->apiAs('POST', '/entry-orders/' . $response->json('order.id') . '/confirm')
            ->assertStatus(422)
            ->assertJsonPath('code', 'BATCH_NAME_IN_USE');
    }

    public function test_the_external_batch_leaves_its_classification_undeclared(): void
    {
        // An external batch only holds the purchase: the own batch the animals go to declares its
        // management system, activity and type.
        $order = $this->createOrder()->assertCreated()->json('order');
        $batch = Batch::findOrFail($order['batch']['id']);

        $this->assertNull($batch->is_confined);
        $this->assertNull($batch->activity_id);
        $this->assertNull($batch->batch_type_id);
        $this->assertArrayNotHasKey('is_confined', $order);
        $this->assertArrayNotHasKey('activity', $order);
        $this->assertArrayNotHasKey('batch_type', $order);
    }
}
