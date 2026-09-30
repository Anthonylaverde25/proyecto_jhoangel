<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

/**
 * Carries a stable error code next to the message, like WeaningOrderDomainException, so the screen
 * can tell the reasons apart without parsing text.
 */
class BirthOrderDomainException extends DomainException
{
    public function __construct(string $message, private readonly string $errorCode = 'BIRTH_ORDER_ERROR')
    {
        parent::__construct($message);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public static function invalidStateTransition(string $from, string $to): self
    {
        return new self("La orden de parición no puede pasar de {$from} a {$to}.", 'INVALID_STATE_TRANSITION');
    }

    public static function reasonRequired(string $action): self
    {
        return new self("Para {$action} la orden hay que indicar el motivo.", 'REASON_REQUIRED');
    }

    public static function notFound(): self
    {
        return new self('La orden de parición no existe.', 'BIRTH_ORDER_NOT_FOUND');
    }

    public static function domainError(string $message, string $code = 'BIRTH_ORDER_ERROR'): self
    {
        return new self($message, $code);
    }
}
