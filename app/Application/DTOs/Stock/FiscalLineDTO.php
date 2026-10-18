<?php

declare(strict_types=1);

namespace App\Application\DTOs\Stock;

final readonly class FiscalLineDTO
{
    /** @param list<TaxInputDTO> $taxes */
    public function __construct(
        public int $itemId,
        public string $inputQuantity,
        public ?int $inputUnitId,
        public ?int $inputPresentationId,
        public string $unitPrice,
        public ?int $batchId,
        public ?int $headsTreated,
        public array $taxes,
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
            (string) $data['unit_price'],
            isset($data['batch_id']) ? (int) $data['batch_id'] : null,
            isset($data['heads_treated']) ? (int) $data['heads_treated'] : null,
            array_map(fn (array $tax) => TaxInputDTO::fromArray($tax), array_values($data['taxes'] ?? [])),
        );
    }
}
