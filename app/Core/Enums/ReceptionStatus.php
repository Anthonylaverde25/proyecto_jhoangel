<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * Whether a caravan listed in a DTE arrived. MISSING is only ever declared, never assumed, and it
 * is final: the DTE lists the caravan, so it stays recorded, but it never comes into possession.
 */
enum ReceptionStatus: string
{
    case PENDING = 'PENDING';
    case RECEIVED = 'RECEIVED';
    case MISSING = 'MISSING';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'En tránsito',
            self::RECEIVED => 'Recibida',
            self::MISSING => 'No llegará',
        };
    }
}
