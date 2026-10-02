<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * Which sexes an entry order brings, declared once for the whole troop.
 */
enum SexComposition: string
{
    case MALE = 'MALE';
    case FEMALE = 'FEMALE';
    case MIXED = 'MIXED';

    public function label(): string
    {
        return match ($this) {
            self::MALE => 'Machos',
            self::FEMALE => 'Hembras',
            self::MIXED => 'Ambos',
        };
    }

    /**
     * Whether a category of the given sex (animal_categories.sex: M, H or BOTH) can hold this
     * composition. A heifer category cannot hold males, a calf category can hold either.
     */
    public function allowsCategorySex(string $categorySex): bool
    {
        return match (strtoupper($categorySex)) {
            'BOTH' => true,
            'M' => $this === self::MALE,
            'H' => $this === self::FEMALE,
            default => false,
        };
    }

    /**
     * The sex every caravan inherits, or null when it has to be declared caravan by caravan.
     */
    public function inheritedSex(): ?AnimalSex
    {
        return match ($this) {
            self::MALE => AnimalSex::MALE,
            self::FEMALE => AnimalSex::FEMALE,
            self::MIXED => null,
        };
    }
}
