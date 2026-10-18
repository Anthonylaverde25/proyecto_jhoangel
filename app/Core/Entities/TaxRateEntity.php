<?php

declare(strict_types=1);

namespace App\Core\Entities;

/**
 * A legal rate of a tax (VAT 21 %, exempt, ...), in force between two dates.
 */
final readonly class TaxRateEntity
{
    public function __construct(
        public int $id,
        public int $taxId,
        public string $code,
        public string $name,
        public string $rate,
        public string $validFrom,
        public ?string $validTo,
    ) {
    }

    /** @param string $date Y-m-d */
    public function isValidOn(string $date): bool
    {
        return $this->validFrom <= $date && ($this->validTo === null || $date <= $this->validTo);
    }
}
