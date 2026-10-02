<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

/**
 * Carries a stable error code next to the message, and optionally the field it belongs to, so the
 * screen can mark the input without parsing text.
 */
class EntryOrderDomainException extends DomainException
{
    public function __construct(
        string $message,
        private readonly string $errorCode = 'ENTRY_ORDER_ERROR',
        private readonly ?string $field = null
    ) {
        parent::__construct($message);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getField(): ?string
    {
        return $this->field;
    }

    public static function invalidStateTransition(string $from, string $to): self
    {
        return new self("La orden de ingreso no puede pasar de {$from} a {$to}.", 'INVALID_STATE_TRANSITION');
    }

    public static function reasonRequired(string $action): self
    {
        return new self("Para {$action} la orden hay que indicar el motivo.", 'REASON_REQUIRED', 'reason');
    }

    public static function notFound(): self
    {
        return new self('La orden de ingreso no existe.', 'ENTRY_ORDER_NOT_FOUND');
    }

    public static function invalid(string $message, string $code, ?string $field = null): self
    {
        return new self($message, $code, $field);
    }
}
