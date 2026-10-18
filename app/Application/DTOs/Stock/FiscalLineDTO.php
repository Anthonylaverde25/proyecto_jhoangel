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
        /** Null only on an unvalued delivery note (RM4). */
        public ?string $unitPrice,
        public ?int $batchId,
        public ?int $headsTreated,
        public array $taxes,
        /** The delivery-note line an invoice line was converted from (DC5). */
        public ?int $deliveryNoteLineId = null,
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
            isset($data['unit_price']) && $data['unit_price'] !== '' ? (string) $data['unit_price'] : null,
            isset($data['batch_id']) ? (int) $data['batch_id'] : null,
            isset($data['heads_treated']) ? (int) $data['heads_treated'] : null,
            array_map(fn (array $tax) => TaxInputDTO::fromArray($tax), array_values($data['taxes'] ?? [])),
            isset($data['delivery_note_line_id']) ? (int) $data['delivery_note_line_id'] : null,
        );
    }
}
