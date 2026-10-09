<?php

declare(strict_types=1);

namespace App\Core\Enums;

enum ServiceOrderMaleStatus: string
{
    case ACTIVE = 'ACTIVE';
    case RETIRED_INJURED = 'RETIRED_INJURED';
    case REPLACED = 'REPLACED';
    case COMPLETED = 'COMPLETED';

    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => 'Activo en Servicio',
            self::RETIRED_INJURED => 'Retirado por Lesión',
            self::REPLACED => 'Sustituido',
            self::COMPLETED => 'Servicio Concluido',
        };
    }
}
