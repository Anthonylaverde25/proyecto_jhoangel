<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

/**
 * Carries a stable error code next to the message, so the screen can tell apart "this order
 * cannot be executed any more" from "this order belongs to another batch" without parsing text.
 */
class TransferOrderDomainException extends DomainException
{
    public function __construct(string $message, private readonly string $errorCode = 'TRANSFER_ORDER_ERROR')
    {
        parent::__construct($message);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public static function invalidStateTransition(string $from, string $to): self
    {
        return new self("La orden no puede pasar de {$from} a {$to}.", 'INVALID_STATE_TRANSITION');
    }

    public static function reasonRequired(string $action): self
    {
        return new self("Para {$action} la orden hay que indicar el motivo.", 'REASON_REQUIRED');
    }

    public static function notFound(): self
    {
        return new self('La orden de transferencia no existe.', 'TRANSFER_ORDER_NOT_FOUND');
    }

    public static function sourceBatchHasActiveOrder(string $code, string $statusLabel): self
    {
        return new self(
            "El lote ya tiene la orden {$code} ({$statusLabel}). Ejecutala, cerrala o anulala antes de crear otra.",
            'SOURCE_BATCH_HAS_ACTIVE_ORDER'
        );
    }

    public static function domainError(string $message, string $code = 'TRANSFER_ORDER_ERROR'): self
    {
        return new self($message, $code);
    }
}
