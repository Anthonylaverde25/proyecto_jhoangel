<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AnimalCategory;
use App\Models\Tenant;
use Database\Seeders\AnimalCategorySeeder;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AnimalCategoryApiTest extends TestCase
{
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate');
        $this->tenant = Tenant::create(['id' => 'test-cat-' . uniqid()]);
        $this->tenant->domains()->create(['domain' => 'test-cat.localhost']);
        tenancy()->initialize($this->tenant);
        $this->seed(AnimalCategorySeeder::class);
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        $this->tenant->delete();
        Artisan::call('migrate:rollback');

        parent::tearDown();
    }

    public function test_can_list_all_animal_categories_with_nested_subcategories(): void
    {
        $response = $this->getJson('http://test-cat.localhost/api/animal-categories');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'code',
                    'name',
                    'sex',
                    'min_age_months',
                    'max_age_months',
                    'min_weight_kg',
                    'max_weight_kg',
                    'is_reproductive',
                    'description',
                    'subcategories' => [
                        '*' => [
                            'id',
                            'category_id',
                            'code',
                            'name',
                            'target_weight_min',
                            'target_weight_max',
                            'description',
                        ],
                    ],
                ],
            ],
        ]);

        $data = $response->json('data');
        $codes = array_column($data, 'code');

        $this->assertContains('TERNERO', $codes);
        $this->assertContains('VAQUILLONA', $codes);
        $this->assertContains('VACA', $codes);
        $this->assertContains('NOVILLITO', $codes);
        $this->assertContains('NOVILLO', $codes);
        $this->assertContains('TORITO', $codes);
        $this->assertContains('TORO', $codes);

        $vaca = collect($data)->firstWhere('code', 'VACA');
        $this->assertNotNull($vaca);
        $this->assertEquals(380.0, (float) $vaca['min_weight_kg']);
        $this->assertEquals(650.0, (float) $vaca['max_weight_kg']);
        $this->assertCount(3, $vaca['subcategories']);

        $subCodes = array_column($vaca['subcategories'], 'code');
        $this->assertContains('RODEO_GENERAL', $subCodes);
        $this->assertContains('PLANTEL', $subCodes);
        $this->assertContains('DESCARTE_CUT', $subCodes);
    }

    public function test_can_fetch_subcategories_by_category_id(): void
    {
        $vaca = AnimalCategory::where('code', 'VACA')->firstOrFail();

        $response = $this->getJson("http://test-cat.localhost/api/animal-categories/{$vaca->id}/subcategories");

        $response->assertStatus(200);
        $response->assertJsonCount(3, 'data');
        $response->assertJsonFragment([
            'code' => 'RODEO_GENERAL',
            'name' => 'Vaca de Rodeo General',
        ]);
    }
}
