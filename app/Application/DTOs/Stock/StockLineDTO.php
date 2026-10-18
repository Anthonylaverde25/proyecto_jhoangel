<?php

declare(strict_types=1);

namespace App\Application\DTOs\Stock;

/**
 * A line of an operation the operator loads (initial stock, consumption): what and how much, in a
 * unit or a presentation. Only initial stock declares a price.
 */
final readonly class StockLineDTO
{
    public function __construct(
        public int $itemId,
        public string $inputQuantity,
        public ?int $inputUnitId,
        public ?int $inputPresentationId,
        public ?string $inputUnitPrice = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (int) $data['item_id'],
            (string) $data['input_quantity'],
            isset($data['input_unit_id']) ? (int) $data['input_unit_id'] : null,
            isset($data['input_presentation_id']) ? (int) $data['input_presentation_id'] : null,
            isset($data['input_unit_price']) ? (string) $data['input_unit_price'] : null,
        );
    }
}
