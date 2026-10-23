<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * Lifecycle of a service order: it follows facts, not the diagnosis. Withdrawing the torada ends a
 * natural service (BULLS_WITHDRAWN); an AI service ends with SERVICE_ENDED (its repaso bulls
 * withdrawn, or ended from the menu). The order is closed (SUCCESS) only by its pregnancy
 * diagnosis, which can be loaded from the first day of service.
 */
enum ServiceOrderStatus: string
{
    case DRAFT = 'DRAFT';
    case APPROVED = 'APPROVED';
    case BULLS_WITHDRAWN = 'BULLS_WITHDRAWN';
    case SERVICE_ENDED = 'SERVICE_ENDED';
    case SUCCESS = 'SUCCESS';
    case REJECTED = 'REJECTED';
    case CANCELLED = 'CANCELLED';

    /** Verdicts can be given: in service (early scan) or with the service already ended. */
    public function acceptsDiagnosis(): bool
    {
        return $this === self::APPROVED || $this->hasServiceEnded();
    }

    /** The service is over but the order still waits for its diagnosis. */
    public function hasServiceEnded(): bool
    {
        return $this === self::BULLS_WITHDRAWN || $this === self::SERVICE_ENDED;
    }

    /** In service or waiting for its diagnosis: the order still has work in the tray. */
    public function isOpen(): bool
    {
        return $this === self::APPROVED || $this->hasServiceEnded();
    }

    /** @return list<string> */
    public static function openValues(): array
    {
        return array_values(array_map(
            static fn (self $status): string => $status->value,
            array_filter(self::cases(), static fn (self $status): bool => $status->isOpen())
        ));
    }
}
