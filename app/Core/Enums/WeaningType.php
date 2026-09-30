<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * How early the calves are taken off their mothers. Declared by whoever orders or registers the
 * weaning, or marked on the DEST-01 sheet; never inferred from the age of the calves.
 */
enum WeaningType: string
{
    case TRADITIONAL = 'TRADITIONAL';
    case ANTICIPATED = 'ANTICIPATED';
    case EARLY = 'EARLY';

    public function label(): string
    {
        return match ($this) {
            self::TRADITIONAL => 'Tradicional',
            self::ANTICIPATED => 'Anticipado',
            self::EARLY => 'Precoz',
        };
    }

    /**
     * The word the sheet prints next to its box, and the one the scan reads back.
     */
    public function sheetText(): string
    {
        return match ($this) {
            self::TRADITIONAL => 'TRADICIONAL',
            self::ANTICIPATED => 'ANTICIPADO',
            self::EARLY => 'PRECOZ',
        };
    }

    /**
     * What a sheet or a screen wrote, as a type. Accepts the code and the printed word, with or
     * without accents or case. Anything else — two boxes crossed ("ANTICIPADO, PRECOZ"), a word
     * that is none of the three — is not a type, and the caller decides what to say about it.
     */
    public static function fromText(?string $text): ?self
    {
        $value = strtoupper(trim(strtr((string) $text, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u'])));

        if ($value === '') {
            return null;
        }

        foreach (self::cases() as $case) {
            if ($value === $case->value || $value === $case->sheetText()) {
                return $case;
            }
        }

        return null;
    }
}
