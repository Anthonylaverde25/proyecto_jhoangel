<?php

declare(strict_types=1);

namespace App\Application\Mappers;

use App\Core\ValueObjects\Decimal;

/**
 * Reads a decimal column the same way on both engines: MySQL returns "5000.0000", SQLite returns
 * 5000 or 0.31108. Always gives back a string with the column's scale.
 */
final class DecimalColumn
{
    public static function read(mixed $value, int $scale): string
    {
        if ($value === null) {
            return Decimal::zero()->toScale($scale);
        }
        if (is_float($value)) {
            $value = sprintf('%.' . ($scale + 2) . 'F', $value);
        }

        return Decimal::of((string) $value)->toScale($scale);
    }

    public static function readNullable(mixed $value, int $scale): ?string
    {
        return $value === null ? null : self::read($value, $scale);
    }
}
