<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * Why the producer declares that a female of a service will not have a diagnosis.
 */
enum NotDiagnosedReason: string
{
    case NOT_GATHERED = 'NOT_GATHERED';
    case NOT_FOUND = 'NOT_FOUND';
    case OTHER = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::NOT_GATHERED => 'No bajó a manga',
            self::NOT_FOUND => 'No se encontró',
            self::OTHER => 'Otro motivo',
        };
    }
}
