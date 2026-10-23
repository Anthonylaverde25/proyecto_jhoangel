<?php

declare(strict_types=1);

namespace App\Core\Enums;

/** What a warehouse is (semen delivery S-0): a general store, or a cryogenic tank where semen lives. */
enum WarehouseKind: string
{
    case GENERAL = 'GENERAL';
    case CRYO_TANK = 'CRYO_TANK';

    public function label(): string
    {
        return match ($this) {
            self::GENERAL => 'Almacén',
            self::CRYO_TANK => 'Termo',
        };
    }
}
