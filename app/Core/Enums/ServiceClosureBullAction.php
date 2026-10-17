<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * What happens with the bulls still in the batch when a service order is closed.
 */
enum ServiceClosureBullAction: string
{
    /** They leave the batch now. */
    case MOVE_NOW = 'MOVE_NOW';
    /** They leave on a later day, through an issued transfer order. */
    case SCHEDULE = 'SCHEDULE';
    /** They stay in the batch. */
    case STAY = 'STAY';

    public function label(): string
    {
        return match ($this) {
            self::MOVE_NOW => 'Se trasladan ahora',
            self::SCHEDULE => 'Traslado programado',
            self::STAY => 'Permanecen en el lote',
        };
    }

    /** The bulls go on sharing the paddock with the females after the order is closed. */
    public function keepsBullsWithFemales(): bool
    {
        return $this !== self::MOVE_NOW;
    }
}
