<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

/**
 * The balance changed between the count and its confirmation (DS6). Rendered as a 409 carrying the
 * current balance, so the screen shows it and the operator confirms again.
 */
final class StaleCountException extends DomainException
{
    public function __construct(
        private readonly string $currentQuantity
    ) {
        parent::__construct('El saldo cambió mientras contabas: revisá lo contado y confirmá de nuevo.');
    }

    public function getCurrentQuantity(): string
    {
        return $this->currentQuantity;
    }
}
