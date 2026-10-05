<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * Where an ING-03 receipt sheet is: issued and out in the field, scanned in part, all its pages
 * received, or replaced by a newer sheet of the same DTE.
 */
enum ReceiptSheetStatus: string
{
    case ISSUED = 'ISSUED';
    case PARTIAL = 'PARTIAL';
    case PROCESSED = 'PROCESSED';
    case REPLACED = 'REPLACED';

    public function label(): string
    {
        return match ($this) {
            self::ISSUED => 'Emitida',
            self::PARTIAL => 'Escaneada en parte',
            self::PROCESSED => 'Procesada',
            self::REPLACED => 'Reemplazada',
        };
    }

    /** Still expected back from the field. */
    public function isActive(): bool
    {
        return $this === self::ISSUED || $this === self::PARTIAL;
    }
}
