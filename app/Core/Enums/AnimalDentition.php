<?php

declare(strict_types=1);

namespace App\Core\Enums;

enum AnimalDentition: int
{
    case MILK_TEETH = 0;   // Diente de Leche / Sin Dientes (DL)
    case TWO_TEETH  = 2;   // 2 Dientes (Pinzas)
    case FOUR_TEETH = 4;   // 4 Dientes (Primeros Medianos / Media Boca)
    case SIX_TEETH  = 6;   // 6 Dientes (Segundos Medianos)
    case FULL_MOUTH = 8;   // 8 Dientes (Extremos / Boca Llena)

    /**
     * Get human-readable Spanish label for livestock dentition.
     */
    public function label(): string
    {
        return match ($this) {
            self::MILK_TEETH => 'Diente de Leche (0 Dientes)',
            self::TWO_TEETH  => '2 Dientes',
            self::FOUR_TEETH => '4 Dientes (Media Boca)',
            self::SIX_TEETH  => '6 Dientes',
            self::FULL_MOUTH => '8 Dientes (Boca Llena)',
        };
    }

    /**
     * Short notation code commonly written on field sheets.
     */
    public function code(): string
    {
        return match ($this) {
            self::MILK_TEETH => 'DL',
            self::TWO_TEETH  => '2D',
            self::FOUR_TEETH => '4D',
            self::SIX_TEETH  => '6D',
            self::FULL_MOUTH => '8D',
        };
    }

    /**
     * Estimated biological age range in months (INTA Balcarce / Carrillo livestock references).
     *
     * @return array{min: int, max: int}
     */
    public function estimatedAgeRangeMonths(): array
    {
        return match ($this) {
            self::MILK_TEETH => ['min' => 0,  'max' => 18],
            self::TWO_TEETH  => ['min' => 18, 'max' => 30],
            self::FOUR_TEETH => ['min' => 30, 'max' => 42],
            self::SIX_TEETH  => ['min' => 42, 'max' => 54],
            self::FULL_MOUTH => ['min' => 54, 'max' => 120],
        };
    }

    /**
     * Try to create from integer teeth value or return null.
     */
    public static function tryFromInt(?int $teeth): ?self
    {
        if ($teeth === null) {
            return null;
        }

        return self::tryFrom($teeth);
    }
}
