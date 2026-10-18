<?php

declare(strict_types=1);

namespace Tests\Feature\ItemCatalog;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class ItemProviderLinkTest extends ItemCatalogTestCase
{
    public function test_the_full_list_replaces_the_links(): void
    {
        $item = $this->createItem();
        $elTala = $this->provider('20-40000001-1');
        $other = $this->provider('20-40000002-2');

        $this->apiAs('PUT', "/items/{$item}/providers", ['providers' => [
            ['provider_id' => $elTala->id, 'lead_time_days' => 2, 'is_preferred' => true],
            ['provider_id' => $other->id],
        ]])->assertOk()->assertJsonCount(2, 'providers')->assertJsonPath('providers_count', 2);

        $this->apiAs('PUT', "/items/{$item}/providers", ['providers' => [['provider_id' => $other->id, 'is_preferred' => true]]])
            ->assertOk()->assertJsonCount(1, 'providers')->assertJsonPath('providers.0.is_preferred', true);

        $this->apiAs('PUT', "/items/{$item}/providers", ['providers' => []])->assertOk()->assertJsonCount(0, 'providers');
    }

    public function test_only_providers_that_sell_items(): void
    {
        $item = $this->createItem();
        $livestockOnly = $this->provider('20-40000003-3', false);

        $this->apiAs('PUT', "/items/{$item}/providers", ['providers' => [['provider_id' => $livestockOnly->id]]])
            ->assertStatus(422)->assertJsonValidationErrors('domain');
    }

    public function test_a_provider_of_another_company_is_refused_by_validation_and_by_the_database(): void
    {
        $item = $this->createItem();
        $foreign = $this->provider('20-40000004-4', true, $this->other);

        $this->apiAs('PUT', "/items/{$item}/providers", ['providers' => [['provider_id' => $foreign->id]]])
            ->assertStatus(422)->assertJsonValidationErrors('providers.0.provider_id');

        $this->expectException(QueryException::class);
        DB::table('item_provider')->insert([
            'company_id' => $this->company->id, 'item_id' => $item, 'provider_id' => $foreign->id,
            'is_preferred' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_one_preferred_provider_per_item(): void
    {
        $item = $this->createItem();
        $a = $this->provider('20-40000005-5');
        $b = $this->provider('20-40000006-6');

        $this->apiAs('PUT', "/items/{$item}/providers", ['providers' => [
            ['provider_id' => $a->id, 'is_preferred' => true],
            ['provider_id' => $b->id, 'is_preferred' => true],
        ]])->assertStatus(422)->assertJsonValidationErrors('domain');

        // The partial unique index refuses it as well.
        $row = fn (int $providerId) => [
            'company_id' => $this->company->id, 'item_id' => $item, 'provider_id' => $providerId,
            'is_preferred' => true, 'created_at' => now(), 'updated_at' => now(),
        ];
        DB::table('item_provider')->insert($row($a->id));
        $this->expectException(QueryException::class);
        DB::table('item_provider')->insert($row($b->id));
    }
}
