<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * Facts of a service order that never block the work but must be settled by someone.
 */
enum ServiceOrderIncidentType: string
{
    /** Diagnosed empty, moved out of the service batch, and found pregnant in a later round. */
    case PREGNANT_OUTSIDE_SERVICE_BATCH = 'PREGNANT_OUTSIDE_SERVICE_BATCH';

    public function label(): string
    {
        return match ($this) {
            self::PREGNANT_OUTSIDE_SERVICE_BATCH => 'Preñada fuera del lote de servicio',
        };
    }
}
