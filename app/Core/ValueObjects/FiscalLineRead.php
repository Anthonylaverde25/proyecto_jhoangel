<?php

declare(strict_types=1);

namespace App\Core\ValueObjects;

/**
 * A line the AI agent transcribed from a provider document (Delivery G, part 04 §1): the
 * description as printed (the only required field), the provider's code when the paper has one, and
 * the quantity, unit price and VAT rate code when they are printed. Nothing is derived.
 */
final readonly class FiscalLineRead
{
    public function __construct(
        public int $position,
        public string $description,
        public ?string $code = null,
        /** Decimal text with 4 places, e.g. "1250.0000". */
        public ?string $quantity = null,
        /** Decimal text with 6 places, per unit as printed. */
        public ?string $unitPrice = null,
        /** VAT_21, VAT_10_5, VAT_EXEMPT…: the tax_rates code, never a bare percentage. */
        public ?string $vatRateCode = null,
    ) {
    }

    /**
     * @param array<string, mixed> $line One entry of the agent's "lines".
     */
    public static function fromAgentLine(array $line, int $fallbackPosition): ?self
    {
        $description = is_string($line['description'] ?? null) ? trim($line['description']) : '';
        if ($description === '') {
            return null;
        }

        $decimal = static fn (mixed $v, int $scale): ?string => is_int($v) || is_float($v)
            ? number_format((float) $v, $scale, '.', '')
            : null;
        $text = static fn (mixed $v): ?string => is_string($v) && trim($v) !== '' ? trim($v) : null;

        return new self(
            position: isset($line['position']) ? (int) $line['position'] : $fallbackPosition,
            description: mb_substr($description, 0, 191),
            code: $text($line['code'] ?? null),
            quantity: $decimal($line['quantity'] ?? null, 4),
            unitPrice: $decimal($line['unit_price'] ?? null, 6),
            vatRateCode: $text($line['vat_rate'] ?? null),
        );
    }
}
