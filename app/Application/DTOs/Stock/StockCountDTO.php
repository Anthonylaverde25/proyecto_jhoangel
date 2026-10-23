<?php

declare(strict_types=1);

namespace App\Application\DTOs\Stock;

/**
 * A physical count of one item in one warehouse (D18), confirmed against the balance the operator
 * saw (DS6).
 */
final readonly class StockCountDTO
{
    public function __construct(
        public int $itemId,
        public ?int $warehouseId,
        public string $systemQuantitySeen,
        public string $countedQuantity,
        public ?int $inputUnitId,
        public ?int $inputPresentationId,
        public ?string $reason,
        public string $occurredAt,
        public string $idempotencyKey,
        public ?int $createdBy = null,
        public ?int $semenLotId = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, ?int $createdBy = null): self
    {
        $reason = isset($data['reason']) ? trim((string) $data['reason']) : '';

        return new self(
            (int) $data['item_id'],
            isset($data['warehouse_id']) ? (int) $data['warehouse_id'] : null,
            (string) $data['system_quantity_seen'],
            (string) $data['counted_quantity'],
            isset($data['input_unit_id']) ? (int) $data['input_unit_id'] : null,
            isset($data['input_presentation_id']) ? (int) $data['input_presentation_id'] : null,
            $reason === '' ? null : $reason,
            (string) $data['occurred_at'],
            (string) $data['idempotency_key'],
            $createdBy,
            isset($data['semen_lot_id']) ? (int) $data['semen_lot_id'] : null,
        );
    }
}
