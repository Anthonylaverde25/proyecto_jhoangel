<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * Optional note on why a female of an AI order was never inseminated (semen S-B, IA12). Being not
 * inseminated is deduced from her having no live insemination; the reason only explains it.
 */
enum NotInseminatedReason: string
{
    case NOT_PRESENTED = 'NOT_PRESENTED';
    case DEVICE_LOST = 'DEVICE_LOST';
    case DISCARDED = 'DISCARDED';
    case OTHER = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::NOT_PRESENTED => 'No se presentó',
            self::DEVICE_LOST => 'Perdió el dispositivo',
            self::DISCARDED => 'Descartada en la manga',
            self::OTHER => 'Otro motivo',
        };
    }
}
