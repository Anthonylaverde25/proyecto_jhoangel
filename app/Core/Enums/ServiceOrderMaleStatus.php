<?php

declare(strict_types=1);

namespace App\Core\Enums;

enum ServiceOrderMaleStatus: string
{
    case ACTIVE = 'ACTIVE';
    case RETIRED_INJURED = 'RETIRED_INJURED';
    case RETIRED_SANITARY = 'RETIRED_SANITARY';
    case REPLACED = 'REPLACED';
    case COMPLETED = 'COMPLETED';

    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => 'Activo en Servicio',
            self::RETIRED_INJURED => 'Retirado por Lesión',
            self::RETIRED_SANITARY => 'Retirado por Rechazo Sanitario',
            self::REPLACED => 'Sustituido',
            self::COMPLETED => 'Servicio Concluido',
        };
    }

    /**
     * Status a bull leaves the order with, by the reason he was taken out.
     */
    public static function forRetirement(BullReplacementReason $reason): self
    {
        return match ($reason) {
            BullReplacementReason::LOW_LIBIDO_RINCONERO => self::REPLACED,
            BullReplacementReason::SANITARY_REJECTION => self::RETIRED_SANITARY,
            default => self::RETIRED_INJURED,
        };
    }
}
