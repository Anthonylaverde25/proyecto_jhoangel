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
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    protected function troop(array $overrides = []): array
    {
        return [
            'provider_id' => $this->provider->id,
            'farm_id' => $this->farm->id,
            'auction_number' => '338',
            'batch_name_mode' => 'AUTO',
            'batch_name' => null,
            'head_count' => 40,
            'category_id' => $this->categoryId('TERNERO'),
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
     * @param list<array<string, mixed>> $animals
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    protected function dte(array $animals, array $overrides = []): array
    {
        return [
            'dte_number' => 'DTE-' . uniqid(),
            'dte_date' => now()->subDay()->toDateString(),
            'entered_at' => now()->toDateString(),
            'animals' => $animals,
            ...$overrides,
        ];
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
