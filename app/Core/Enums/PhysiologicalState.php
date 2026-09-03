<?php

declare(strict_types=1);

namespace App\Core\Enums;

enum PhysiologicalState: string
{
    case IN_SERVICE = 'in_service';
    case PREGNANT_LACTATING = 'pregnant_lactating';
    case PREGNANT_DRY = 'pregnant_dry';
    case EMPTY_LACTATING = 'empty_lactating';
    case EMPTY_DRY = 'empty_dry';
    case CALVED = 'calved';
    case UNKNOWN = 'unknown';

    /**
     * Get a human-readable descriptive label in Spanish for presentation.
     */
    public function label(): string
    {
        return match ($this) {
            self::IN_SERVICE          => 'En Servicio / Entore',
            self::PREGNANT_LACTATING  => 'Preñada y Lactando',
            self::PREGNANT_DRY        => 'Preñada y Seca',
            self::EMPTY_LACTATING     => 'Vacía y Lactando',
            self::EMPTY_DRY           => 'Vacía y Seca',
            self::CALVED              => 'Parida Reciente',
            self::UNKNOWN             => 'Sin Definir / No Aplica',
        };
    }
}
