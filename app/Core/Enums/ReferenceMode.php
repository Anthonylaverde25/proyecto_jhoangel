<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * How an ING-03 sheet asks for the breed, coat and category of each line, when the order leaves
 * them to each animal: written in words (RAZA, PELAJE and CATEGORÍA columns), or by the reference
 * printed in the header (a breed letter and a category number).
 */
enum ReferenceMode: string
{
    case WRITTEN = 'WRITTEN';
    case CODE = 'CODE';

    public function label(): string
    {
        return match ($this) {
            self::WRITTEN => 'Raza y categoría escritas',
            self::CODE => 'Raza y categoría por código',
        };
    }
}
