<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * An incident stays open until someone writes down how it was settled. A resolved one is never
 * reopened: if the matter comes back, another is raised.
 */
enum ServiceOrderIncidentStatus: string
{
    case OPEN = 'OPEN';
    case RESOLVED = 'RESOLVED';

    public function label(): string
    {
        return match ($this) {
            self::OPEN => 'Abierta',
            self::RESOLVED => 'Resuelta',
        };
    }
}
