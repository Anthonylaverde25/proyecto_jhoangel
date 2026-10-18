<?php

declare(strict_types=1);

namespace App\Core\Entities;

/**
 * A tax charged on a provider-document line or on the whole document (DS16). is_recoverable is the
 * tax's flag when the document was registered: a later catalog change never rewrites past costs.
 */
final readonly class FiscalDocumentTaxEntity
{
    public function __construct(
        public int $taxId,
        public string $taxCode,
        public string $taxName,
        public ?int $taxRateId,
        public ?string $rateName,
        public ?string $rate,
        public ?string $taxableBase,
        public string $amount,
        public bool $isRecoverable,
        public ?int $id = null,
    ) {
    }
}
