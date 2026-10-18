<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * Class of a provider document type (DS15). The type row says the letter and whether VAT is
 * discriminated; the kind says what the paper does to stock and cost.
 */
enum FiscalDocumentKind: string
{
    case INVOICE = 'INVOICE';
    case CREDIT_NOTE = 'CREDIT_NOTE';
    case TICKET = 'TICKET';
    case DELIVERY_NOTE = 'DELIVERY_NOTE';

    /** The stock operation its stocked lines generate (DS14). */
    public function stockOperation(): ?ItemDocumentType
    {
        return match ($this) {
            self::INVOICE, self::TICKET => ItemDocumentType::PURCHASE,
            self::CREDIT_NOTE => ItemDocumentType::RETURN_TO_PROVIDER,
            self::DELIVERY_NOTE => null,
        };
    }

    /** Invoices and credit notes carry point of sale and number; a ticket a free reference. */
    public function requiresNumber(): bool
    {
        return $this !== self::TICKET;
    }
}
