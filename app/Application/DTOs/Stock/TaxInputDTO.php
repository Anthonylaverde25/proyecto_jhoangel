<?php

declare(strict_types=1);

namespace App\Application\DTOs\Stock;

/**
 * A tax as typed from the paper. VAT sends its legal rate and the amount is computed; any other tax
 * sends the amount printed on the paper.
 */
final readonly class TaxInputDTO
{
    public function __construct(
        public int $taxId,
        public ?int $taxRateId = null,
        public ?string $rate = null,
        public ?string $taxableBase = null,
        public ?string $amount = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $decimal = fn (string $key): ?string => isset($data[$key]) && $data[$key] !== '' ? (string) $data[$key] : null;

        return new self(
            (int) $data['tax_id'],
            isset($data['tax_rate_id']) ? (int) $data['tax_rate_id'] : null,
            $decimal('rate'),
            $decimal('taxable_base'),
            $decimal('amount'),
        );
    }
}
