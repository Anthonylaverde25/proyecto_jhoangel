<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

/** Carries a stable error code next to the message, like the other order exceptions. */
class WeighingOrderDomainException extends DomainException
{
    public function __construct(string $message, private readonly string $errorCode = 'WEIGHING_ORDER_ERROR')
    {
        parent::__construct($message);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public static function domainError(string $message, string $code = 'WEIGHING_ORDER_ERROR'): self
    {
        return new self($message, $code);
    }
}
