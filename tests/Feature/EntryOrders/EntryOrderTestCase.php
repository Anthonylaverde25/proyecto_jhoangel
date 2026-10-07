<?php

declare(strict_types=1);

namespace Tests\Feature\EntryOrders;

use App\Models\AnimalCategory;
use App\Models\Breed;
use App\Models\Color;
use App\Models\Farm;
use App\Models\Provider;
use Tests\Feature\Veterinary\VeterinaryTestCase;

/**
 * A seller with two establishments, and the catalogues an entry order points to: the smallest
 * setting a purchase of external livestock needs.
 */
abstract class EntryOrderTestCase extends VeterinaryTestCase
{
    protected Provider $provider;
    protected Farm $farm;
    protected Farm $otherFarm;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = Provider::create([
            'name' => 'Consignataria Prueba EO',
            'cuit' => '30-70000001-' . random_int(0, 9),
            'is_active' => true,
        ]);

        $this->farm = Farm::create([
            'company_id' => $this->company->id,
            'provider_id' => $this->provider->id,
            'name' => 'La Porteña EO',
            'renspa' => '01.234.5.67890/00',
            'is_active' => true,
        ]);

        $this->otherFarm = Farm::create([
            'company_id' => $this->company->id,
            'provider_id' => $this->provider->id,
            'name' => 'El Ombú EO',
            'renspa' => '01.234.5.67890/01',
            'is_active' => true,
        ]);
    }

    /**
     * A valid troop: 40 calves of both sexes (25/15), two breeds, from an auction.
     *
     * Most tests buy a single category: `head_count` and `category_id` in the overrides become its
     * only line (null leaves it out). `categories` declares the lines as they are sent.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    protected function troop(array $overrides = []): array
    {
        $head = array_key_exists('head_count', $overrides) ? $overrides['head_count'] : 40;
        $category = array_key_exists('category_id', $overrides) ? $overrides['category_id'] : $this->categoryId('TERNERO');
        unset($overrides['head_count'], $overrides['category_id']);

        return [
            'provider_id' => $this->provider->id,
            'farm_id' => $this->farm->id,
            'auction_number' => '338',
            'batch_name_mode' => 'AUTO',
            'batch_name' => null,
            'categories' => $category !== null ? [['category_id' => $category, 'head_count' => $head]] : [],
            'sex_composition' => 'MIXED',
            'male_count' => 25,
            'female_count' => 15,
            'condition' => 'GOOD',
            'age_min_months' => 9,
            'age_max_months' => 10,
            'knows_to_eat' => true,
            'tick_vaccinated' => false,
            'shrink_percent' => 3.5,
            'estimated_weight' => 180,
            'min_weight' => 160,
            'max_weight' => 200,
            'purchase_date' => now()->subDays(2)->toDateString(),
            'responsable' => 'Encargado EO',
            'observations' => 'Compra de prueba',
            'breeds' => [
                ['breed_id' => $this->breedId('Braford'), 'color_id' => $this->colorId('Colorado')],
                ['breed_id' => $this->breedId('Brangus'), 'color_id' => $this->colorId('Negro')],
            ],
            ...$overrides,
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return \Illuminate\Testing\TestResponse
     */
    protected function createOrder(array $overrides = [], bool $confirm = true)
    {
        return $this->apiAs('POST', '/entry-orders', [...$this->troop($overrides), 'confirm' => $confirm]);
    }

    /**
     * A DTE: the head it declares.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    protected function dte(int $headCount, array $overrides = []): array
    {
        return [
            'dte_number' => 'DTE-' . uniqid(),
            'dte_date' => now()->subDay()->toDateString(),
            'head_count' => $headCount,
            ...$overrides,
        ];
    }

    /**
     * A DTE for "Registrar ingreso": the animals arrive with it, so it carries their entry day and
     * the caravans received. By default it declares as many head as animals.
     *
     * @param list<array<string, mixed>> $animals
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    protected function registerDte(array $animals, array $overrides = []): array
    {
        return $this->dte(count($animals), ['entered_at' => now()->toDateString(), 'animals' => $animals, ...$overrides]);
    }

    /**
     * Loads a DTE on the order and returns the order as the response left it.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    protected function loadDte(int $orderId, int $headCount, array $overrides = []): array
    {
        return $this->apiAs('POST', "/entry-orders/{$orderId}/dtes", $this->dte($headCount, $overrides))
            ->assertCreated()
            ->json('order');
    }

    /**
     * @param array<string, mixed> $payload
     * @return \Illuminate\Testing\TestResponse
     */
    protected function receive(int $orderId, array $payload)
    {
        return $this->apiAs('POST', "/entry-orders/{$orderId}/receive", [
            'method' => 'MANUAL',
            'received_at' => now()->toDateString(),
            'animals' => [],
            ...$payload,
        ]);
    }

    /**
     * Receives animals on a DTE of the order (by its position in the order) and returns the order.
     *
     * @param array<string, mixed> $order
     * @param list<array<string, mixed>> $animals
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    protected function receiveOn(array $order, array $animals, int $dteIndex = 0, array $extra = []): array
    {
        return $this->receive($order['id'], ['dte_id' => $order['dtes'][$dteIndex]['id'], 'animals' => $animals, ...$extra])
            ->assertOk()
            ->json('order');
    }

    /**
     * Caravans EO-{prefix}-1..n, with the given sex and breed letter position.
     *
     * @return list<array<string, mixed>>
     */
    protected function animals(string $prefix, int $count, ?string $sex = 'M', ?int $breed = 1, ?float $weight = 180): array
    {
        return array_map(fn (int $i) => [
            'caravana' => "EO-{$prefix}-{$i}",
            'sex' => $sex,
            'breed_position' => $breed,
            'weight' => $weight,
        ], range(1, $count));
    }

    protected function categoryId(string $code): int
    {
        return (int) AnimalCategory::where('code', $code)->value('id');
    }

    protected function breedId(string $name): int
    {
        return (int) Breed::where('name', $name)->value('id');
    }

    protected function colorId(string $name): int
    {
        return (int) Color::where('name', $name)->value('id');
    }
}
