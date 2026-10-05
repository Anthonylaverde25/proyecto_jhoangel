<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * What an entry order has to settle with the provider: more head than were bought, caravans
 * listed in a DTE that will not arrive, head bought that never got a DTE, or an animal that
 * arrived with a caravan no DTE lists.
 */
enum EntryOrderIncidentType: string
{
    case EXCESS_HEAD = 'EXCESS_HEAD';
    case EXCESS_MALES = 'EXCESS_MALES';
    case EXCESS_FEMALES = 'EXCESS_FEMALES';
    case MISSING_HEAD = 'MISSING_HEAD';
    case MISSING_DTE = 'MISSING_DTE';
    case UNLISTED_CARAVAN = 'UNLISTED_CARAVAN';

    public function label(): string
    {
        return match ($this) {
            self::EXCESS_HEAD => 'Cabezas de más',
            self::EXCESS_MALES => 'Machos de más',
            self::EXCESS_FEMALES => 'Hembras de más',
            self::MISSING_HEAD => 'Caravanas que no llegarán',
            self::MISSING_DTE => 'Cabezas sin DTE',
            self::UNLISTED_CARAVAN => 'Caravana sin DTE',
        };
    }
}
