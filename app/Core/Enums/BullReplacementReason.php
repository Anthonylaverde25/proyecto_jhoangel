<?php

declare(strict_types=1);

namespace App\Core\Enums;

enum BullReplacementReason: string
{
    case LAMENESS_FOOT = 'LAMENESS_FOOT';
    case PENIS_INJURY = 'PENIS_INJURY';
    case LOW_LIBIDO_RINCONERO = 'LOW_LIBIDO_RINCONERO';
    case AGGRESSION = 'AGGRESSION';
    case DEATH = 'DEATH';
    case SANITARY_REJECTION = 'SANITARY_REJECTION';
    case OTHER = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::LAMENESS_FOOT => 'Manquera / Afección Podal',
            self::PENIS_INJURY => 'Lesión Peneana / Prepucial',
            self::LOW_LIBIDO_RINCONERO => 'Toro Rinconero / Baja Libido',
            self::AGGRESSION => 'Agresividad Extrema / Peleas',
            self::DEATH => 'Muerte en Potrero',
            self::SANITARY_REJECTION => 'Rechazo Sanitario (enfermedad contagiosa)',
            self::OTHER => 'Otra Causa Veterinaria',
        };
    }
}
