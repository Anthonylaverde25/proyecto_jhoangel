<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Entities\AnimalCategoryEntity;
use App\Core\Entities\AnimalSubcategoryEntity;
use App\Core\Services\AnimalCategoryResolution;
use App\Core\Services\AnimalCategoryTextResolver;
use PHPUnit\Framework\TestCase;

class AnimalCategoryTextResolverTest extends TestCase
{
    private AnimalCategoryTextResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        // The catalog as AnimalCategorySeeder writes it.
        $this->resolver = new AnimalCategoryTextResolver([
            new AnimalCategoryEntity(1, 'TERNERO', 'Ternero', 'BOTH'),
            new AnimalCategoryEntity(2, 'VAQUILLONA', 'Vaquillona', 'H', subcategories: [
                new AnimalSubcategoryEntity(21, 2, 'REPOSICION', 'Vaquillona de Reposición'),
                new AnimalSubcategoryEntity(22, 2, 'DESCARTE_FAENA', 'Vaquillona Descarte / Faena'),
            ]),
            new AnimalCategoryEntity(3, 'VACA', 'Vaca Adulta', 'H', subcategories: [
                new AnimalSubcategoryEntity(31, 3, 'RODEO_GENERAL', 'Vaca de Rodeo General'),
                new AnimalSubcategoryEntity(32, 3, 'PLANTEL', 'Vaca de Plantel / Cabaña'),
                new AnimalSubcategoryEntity(33, 3, 'DESCARTE_CUT', 'Vaca CUT / Descarte'),
            ]),
            new AnimalCategoryEntity(4, 'NOVILLITO', 'Novillito', 'M'),
            new AnimalCategoryEntity(5, 'NOVILLO', 'Novillo', 'M'),
            new AnimalCategoryEntity(6, 'TORITO', 'Torito', 'M'),
            new AnimalCategoryEntity(7, 'TORO', 'Toro Reproductor', 'M'),
        ]);
    }

    public function test_a_category_alone_resolves_without_subcategory(): void
    {
        $this->assertPair($this->resolver->resolve('Novillito', 'M'), 4, null);
        $this->assertPair($this->resolver->resolve('  novillo ', 'M'), 5, null);
        $this->assertPair($this->resolver->resolve('Vaquillona', 'H'), 2, null);
    }

    public function test_a_subcategory_alone_brings_its_category(): void
    {
        $this->assertPair($this->resolver->resolve('Reposición', 'H'), 2, 21);
        $this->assertPair($this->resolver->resolve('REPOSICION', 'H'), 2, 21);
        $this->assertPair($this->resolver->resolve('Plantel', 'H'), 3, 32);
        $this->assertPair($this->resolver->resolve('CUT', 'H'), 3, 33);
    }

    public function test_category_and_subcategory_together(): void
    {
        $this->assertPair($this->resolver->resolve('Vaquillona / Reposición', 'H'), 2, 21);
        $this->assertPair($this->resolver->resolve('Vaquillona/Desc', 'H'), 2, 22);
        $this->assertPair($this->resolver->resolve('Vaca / Descarte', 'H'), 3, 33);
    }

    public function test_the_canonical_label_resolves_to_itself(): void
    {
        foreach ([[2, 21], [2, 22], [3, 31], [3, 32], [3, 33], [4, null], [7, null]] as [$categoryId, $subcategoryId]) {
            $byIds = $this->resolver->resolveIds($categoryId, $subcategoryId, $categoryId <= 3 ? 'H' : 'M');
            $this->assertTrue($byIds->isResolved());

            $label = AnimalCategoryTextResolver::label($byIds->category, $byIds->subcategory);
            $this->assertPair($this->resolver->resolve($label, $categoryId <= 3 ? 'H' : 'M'), $categoryId, $subcategoryId);
        }
    }

    public function test_a_unique_abbreviation_resolves(): void
    {
        $this->assertPair($this->resolver->resolve('VAQ', 'H'), 2, null);
        $this->assertPair($this->resolver->resolve('Torito', 'M'), 6, null);
        $this->assertPair($this->resolver->resolve('Toro', 'M'), 7, null);
    }

    public function test_text_that_fits_two_options_is_ambiguous(): void
    {
        $descarte = $this->resolver->resolve('Descarte', 'H');
        $this->assertSame(AnimalCategoryResolution::AMBIGUOUS, $descarte->status);
        $this->assertEqualsCanonicalizing(['Vaquillona / Descarte / Faena', 'Vaca Adulta / CUT / Descarte'], $descarte->candidates);

        $this->assertSame(AnimalCategoryResolution::AMBIGUOUS, $this->resolver->resolve('NOVILL', 'M')->status);
        $this->assertSame(AnimalCategoryResolution::AMBIGUOUS, $this->resolver->resolve('TOR', 'M')->status);
    }

    public function test_the_sex_of_the_animal_filters_before_deciding(): void
    {
        $this->assertSame(AnimalCategoryResolution::SEX_MISMATCH, $this->resolver->resolve('Novillito', 'H')->status);
        $this->assertSame(AnimalCategoryResolution::SEX_MISMATCH, $this->resolver->resolve('Reposición', 'M')->status);
        $this->assertPair($this->resolver->resolve('Ternero', 'H'), 1, null);
    }

    public function test_unknown_text_is_not_found(): void
    {
        $this->assertSame(AnimalCategoryResolution::NOT_FOUND, $this->resolver->resolve('Búfalo', 'M')->status);
        $this->assertSame(AnimalCategoryResolution::NOT_FOUND, $this->resolver->resolve('NO', 'M')->status);
    }

    public function test_ids_check_that_the_subcategory_belongs_to_the_category(): void
    {
        $this->assertSame(AnimalCategoryResolution::NOT_FOUND, $this->resolver->resolveIds(2, 33, 'H')->status);
        $this->assertSame(AnimalCategoryResolution::SEX_MISMATCH, $this->resolver->resolveIds(4, null, 'H')->status);
    }

    public function test_printed_blank_marks(): void
    {
        foreach (['', '  ', '-', '—', '/'] as $blank) {
            $this->assertTrue(AnimalCategoryTextResolver::isBlank($blank));
        }

        $this->assertFalse(AnimalCategoryTextResolver::isBlank('Novillito'));
    }

    private function assertPair(AnimalCategoryResolution $resolution, int $categoryId, ?int $subcategoryId): void
    {
        $this->assertTrue($resolution->isResolved(), "Expected RESOLVED, got {$resolution->status} (" . implode(', ', $resolution->candidates) . ')');
        $this->assertSame($categoryId, $resolution->category?->getId());
        $this->assertSame($subcategoryId, $resolution->subcategory?->getId());
    }
}
