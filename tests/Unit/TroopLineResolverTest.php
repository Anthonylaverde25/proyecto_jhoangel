<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Entities\BreedEntity;
use App\Core\Entities\EntryOrderBreedEntity;
use App\Core\Entities\EntryOrderCategoryEntity;
use App\Core\Enums\AnimalSex;
use App\Core\Enums\SexComposition;
use App\Core\Services\TroopLineResolver;
use App\Core\ValueObjects\EntryTroop;
use App\Core\ValueObjects\TroopLineMatch;
use PHPUnit\Framework\TestCase;

/**
 * The breed, coat and category written on an ING-03 printed with words, against the order's lines.
 */
class TroopLineResolverTest extends TestCase
{
    private TroopLineResolver $resolver;
    private EntryTroop $troop;

    protected function setUp(): void
    {
        parent::setUp();

        // The catalog as BreedSeeder and ColorSeeder write it (the part these tests use).
        $this->resolver = new TroopLineResolver([
            new BreedEntity(1, 'Braford', [['id' => 2, 'name' => 'Colorado', 'code' => 'CO'], ['id' => 3, 'name' => 'Pampa', 'code' => 'PA']]),
            new BreedEntity(2, 'Brangus', [['id' => 1, 'name' => 'Negro', 'code' => 'NE'], ['id' => 2, 'name' => 'Colorado', 'code' => 'CO']]),
            new BreedEntity(3, 'Angus', [['id' => 1, 'name' => 'Negro', 'code' => 'NE'], ['id' => 2, 'name' => 'Colorado', 'code' => 'CO']]),
            new BreedEntity(4, 'Hereford', [['id' => 3, 'name' => 'Pampa', 'code' => 'PA']]),
        ]);

        // Braford Colorado (A), Braford Pampa (B) and Brangus Negro (C); Novillito, Torito, Vaquillona.
        $this->troop = $this->troop([
            new EntryOrderBreedEntity(null, 1, 1, 2, 'Braford', 'Colorado'),
            new EntryOrderBreedEntity(null, 2, 1, 3, 'Braford', 'Pampa'),
            new EntryOrderBreedEntity(null, 3, 2, 1, 'Brangus', 'Negro'),
        ]);
    }

    /**
     * @param EntryOrderBreedEntity[] $breeds
     */
    private function troop(array $breeds): EntryTroop
    {
        return new EntryTroop(
            providerId: 1,
            farmId: 1,
            auctionNumber: null,
            categories: [
                new EntryOrderCategoryEntity(null, 1, 10, 10, 'Novillito', 'M'),
                new EntryOrderCategoryEntity(null, 2, 11, 5, 'Torito', 'M'),
                new EntryOrderCategoryEntity(null, 3, 12, 5, 'Vaquillona', 'H'),
            ],
            sexComposition: SexComposition::MIXED,
            maleCount: 15,
            femaleCount: 5,
            condition: null,
            ageMinMonths: null,
            ageMaxMonths: null,
            knowsToEat: null,
            tickVaccinated: null,
            shrinkPercent: null,
            estimatedWeight: null,
            minWeight: null,
            maxWeight: null,
            purchaseDate: '2026-10-01',
            responsable: null,
            observations: null,
            breeds: $breeds
        );
    }

    public function test_the_breed_and_coat_written_in_full_name_their_line(): void
    {
        $match = $this->resolver->breedLine($this->troop, 'Braford', 'Pampa');

        $this->assertTrue($match->isMatched());
        $this->assertSame(2, $match->position);
    }

    public function test_case_accents_punctuation_prefixes_codes_and_gender_are_tolerated(): void
    {
        $this->assertSame(1, $this->resolver->breedLine($this->troop, 'braf.', 'col')->position);
        $this->assertSame(1, $this->resolver->breedLine($this->troop, 'BRAFORD', 'CO')->position);
        $this->assertSame(3, $this->resolver->breedLine($this->troop, 'Brangus', 'Negra')->position);
        $this->assertSame(1, $this->resolver->breedLine($this->troop, 'Braford', 'Colorada')->position);
    }

    public function test_breed_and_coat_written_together_in_the_breed_cell(): void
    {
        $this->assertSame(2, $this->resolver->breedLine($this->troop, 'Braford Pampa', null)->position);
    }

    public function test_a_coat_left_blank_is_certain_only_with_one_line_of_the_breed(): void
    {
        $this->assertSame(3, $this->resolver->breedLine($this->troop, 'Brangus', null)->position);

        $match = $this->resolver->breedLine($this->troop, 'Braford', null);

        $this->assertTrue($match->isError());
        $this->assertSame('COLOR_REQUIRED', $match->code);
        $this->assertSame('color_text', $match->field);
        $this->assertSame(['Colorado', 'Pampa'], $match->candidates);
    }

    public function test_a_prefix_that_fits_two_breeds_is_never_guessed(): void
    {
        $match = $this->resolver->breedLine($this->troop, 'Bra', 'Colorado');

        $this->assertSame(TroopLineMatch::AMBIGUOUS, $match->status);
        $this->assertSame('BREED_AMBIGUOUS', $match->code);
        $this->assertSame(['Braford', 'Brangus'], $match->candidates);
    }

    public function test_the_letter_of_the_reference_still_names_the_line(): void
    {
        $this->assertSame(3, $this->resolver->breedLine($this->troop, 'c', null)->position);
    }

    public function test_a_breed_of_the_catalog_the_order_does_not_declare_is_outside_the_order(): void
    {
        $match = $this->resolver->breedLine($this->troop, 'Angus', 'Negro');

        $this->assertTrue($match->isOutsideOrder());
        $this->assertSame(3, $match->breedId);
        $this->assertSame(1, $match->colorId);
        $this->assertSame('Angus Negro', $match->label);
    }

    public function test_a_coat_the_order_does_not_declare_for_the_breed_is_outside_the_order(): void
    {
        $match = $this->resolver->breedLine($this->troop, 'Brangus', 'Colorado');

        $this->assertTrue($match->isOutsideOrder());
        $this->assertSame('Brangus Colorado', $match->label);
    }

    public function test_text_that_names_no_breed_is_unknown_and_says_what_the_order_has(): void
    {
        $match = $this->resolver->breedLine($this->troop, 'Xyz', null);

        $this->assertSame('BREED_UNKNOWN_TEXT', $match->code);
        $this->assertSame(['Braford Colorado', 'Braford Pampa', 'Brangus Negro'], $match->candidates);
    }

    public function test_an_order_breed_without_coat_takes_any_coat(): void
    {
        $troop = $this->troop([
            new EntryOrderBreedEntity(null, 1, 4, null, 'Hereford', null),
            new EntryOrderBreedEntity(null, 2, 2, 1, 'Brangus', 'Negro'),
        ]);

        $this->assertSame(1, $this->resolver->breedLine($troop, 'Hereford', 'Pampa')->position);
    }

    public function test_a_category_written_by_name_prefix_or_number(): void
    {
        $this->assertSame(2, $this->resolver->categoryLine($this->troop, 'Torito', AnimalSex::MALE)->position);
        $this->assertSame(1, $this->resolver->categoryLine($this->troop, 'novill', AnimalSex::MALE)->position);
        $this->assertSame(3, $this->resolver->categoryLine($this->troop, '3', null)->position);
    }

    public function test_a_category_that_is_not_of_the_order_is_unknown(): void
    {
        $match = $this->resolver->categoryLine($this->troop, 'Vaca', AnimalSex::FEMALE);

        $this->assertSame('CATEGORY_UNKNOWN_TEXT', $match->code);
        $this->assertSame(['Novillito', 'Torito', 'Vaquillona'], $match->candidates);
    }
}
