<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * State of a purchased troop as declared by the buyer. A closed list for now: adding a grade is
 * adding a case, the column is a plain string.
 */
enum TroopCondition: string
{
    case REGULAR = 'REGULAR';
    case GOOD = 'GOOD';
    case VERY_GOOD = 'VERY_GOOD';
    case EXCELLENT = 'EXCELLENT';

    public function label(): string
    {
        return match ($this) {
            self::REGULAR => 'Regular',
            self::GOOD => 'Bueno',
            self::VERY_GOOD => 'Muy bueno',
            self::EXCELLENT => 'Excelente',
        };
    }

    /**
     * The Spanish word marked on paper ("MUY BUENO", "bueno"), to the code. Null when it matches none.
     */
    public static function fromSheetText(?string $text): ?self
    {
        $normalized = strtoupper(trim(strtr((string) $text, ['_' => ' ', '-' => ' '])));
        $normalized = (string) preg_replace('/\s+/', ' ', $normalized);

        return match ($normalized) {
            'REGULAR' => self::REGULAR,
            'BUENO', 'BUENA' => self::GOOD,
            'MUY BUENO', 'MUY BUENA' => self::VERY_GOOD,
            'EXCELENTE' => self::EXCELLENT,
            // The code itself ("VERY_GOOD") is accepted as well.
            default => self::tryFrom(str_replace(' ', '_', $normalized)),
        };
    }
}
