<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * Lifecycle of a service order: it follows the bulls, not the diagnosis. Withdrawing the torada
 * ends the service (BULLS_WITHDRAWN); the order is closed (SUCCESS) only by its pregnancy
 * diagnosis, which can be loaded from the first day of service.
 */
enum ServiceOrderStatus: string
{
    case DRAFT = 'DRAFT';
    case APPROVED = 'APPROVED';
    case BULLS_WITHDRAWN = 'BULLS_WITHDRAWN';
    case SUCCESS = 'SUCCESS';
    case REJECTED = 'REJECTED';
    case CANCELLED = 'CANCELLED';

    /** Verdicts can be given: in service (early scan) or with the bulls already withdrawn. */
    public function acceptsDiagnosis(): bool
    {
        return $this === self::APPROVED || $this === self::BULLS_WITHDRAWN;
    }
}
