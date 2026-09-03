<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application\Services\BreedAndColorResolver;
use App\Models\Tenant;
use Database\Seeders\BreedSeeder;
use Database\Seeders\ColorSeeder;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class BreedAndColorResolverTest extends TestCase
{
    private Tenant $tenant;
    private BreedAndColorResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate');
        $this->tenant = Tenant::create(['id' => 'test-resolver-' . uniqid()]);
        $this->tenant->domains()->create(['domain' => 'test-resolver.localhost']);
        tenancy()->initialize($this->tenant);

        $this->seed(BreedSeeder::class);
        $this->seed(ColorSeeder::class);

        $this->resolver = new BreedAndColorResolver();
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

    public function test_resolves_angus_negro_compound_string(): void
    {
        $result = $this->resolver->resolve('Angus Negro');

        $this->assertTrue($result->isResolved);
        $this->assertEquals('Angus', $result->breedName);
        $this->assertEquals('Negro', $result->colorName);
        $this->assertNotNull($result->breedId);
        $this->assertNotNull($result->colorId);
    }

    public function test_resolves_angus_colorado_compound_string(): void
    {
        $result = $this->resolver->resolve('Angus Colorado');

        $this->assertTrue($result->isResolved);
        $this->assertEquals('Angus', $result->breedName);
        $this->assertEquals('Colorado', $result->colorName);
        $this->assertNotNull($result->breedId);
        $this->assertNotNull($result->colorId);
    }

    public function test_resolves_hereford_single_breed_string(): void
    {
        $result = $this->resolver->resolve('Hereford');

        $this->assertTrue($result->isResolved);
        $this->assertEquals('Hereford', $result->breedName);
        $this->assertNotNull($result->breedId);
    }

    public function test_resolves_cruza_careta_with_pampa_color(): void
    {
        $result = $this->resolver->resolve('Cruza Careta');

        $this->assertTrue($result->isResolved);
        $this->assertEquals('Cruza', $result->breedName);
        $this->assertEquals('Pampa', $result->colorName);
    }

    public function test_resolves_brangus_colorado(): void
    {
        $result = $this->resolver->resolve('Brangus Colorado');

        $this->assertTrue($result->isResolved);
        $this->assertEquals('Brangus', $result->breedName);
        $this->assertEquals('Colorado', $result->colorName);
    }

    public function test_resolves_fuzzy_ocr_errors(): void
    {
        $result = $this->resolver->resolve('angos');

        $this->assertTrue($result->isResolved);
        $this->assertEquals('Angus', $result->breedName);
    }

    public function test_handles_null_or_empty(): void
    {
        $result1 = $this->resolver->resolve(null);
        $this->assertFalse($result1->isResolved);

        $result2 = $this->resolver->resolve('   ');
        $this->assertFalse($result2->isResolved);
    }
}
