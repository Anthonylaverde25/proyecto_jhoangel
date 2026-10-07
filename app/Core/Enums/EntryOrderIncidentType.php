<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * What an entry order has to settle with the provider: DTEs declaring more head than were bought,
 * more animals (or more of a sex) arriving than declared, head of a DTE that will not arrive, or
 * head bought that never got a DTE, or animals of a breed the purchase does not declare.
 */
enum EntryOrderIncidentType: string
{
    case EXCESS_HEAD = 'EXCESS_HEAD';
    case EXCESS_MALES = 'EXCESS_MALES';
    case EXCESS_FEMALES = 'EXCESS_FEMALES';
    case MISSING_HEAD = 'MISSING_HEAD';
    case MISSING_DTE = 'MISSING_DTE';
    case ARRIVAL_EXCESS = 'ARRIVAL_EXCESS';
    case BREED_MISMATCH = 'BREED_MISMATCH';

    public function label(): string
    {
        return match ($this) {
            self::EXCESS_HEAD => 'Cabezas de más',
            self::EXCESS_MALES => 'Machos de más',
            self::EXCESS_FEMALES => 'Hembras de más',
            self::MISSING_HEAD => 'Cabezas que no llegarán',
            self::MISSING_DTE => 'Cabezas sin DTE',
            self::ARRIVAL_EXCESS => 'Cabezas de más en la llegada',
            self::BREED_MISMATCH => 'Raza distinta a la comprada',
        };
    }
}
