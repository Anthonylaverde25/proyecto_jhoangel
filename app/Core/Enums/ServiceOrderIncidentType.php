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
    /** Moved into a batch under natural service while another order still waits for her tacto. */
    case JOINED_WITH_PENDING_TACTO = 'JOINED_WITH_PENDING_TACTO';

    public function label(): string
    {
        return match ($this) {
            self::PREGNANT_OUTSIDE_SERVICE_BATCH => 'Preñada fuera del lote de servicio',
            self::JOINED_WITH_PENDING_TACTO => 'Entró al servicio con tacto pendiente',
        };
    }
}
