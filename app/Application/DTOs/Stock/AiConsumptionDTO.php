<?php

declare(strict_types=1);

namespace App\Application\DTOs\Stock;

use App\Core\ValueObjects\Decimal;

/**
 * A consumption of one article to one batch, as an AI client asks for it. Previewing, warehouse and
 * heads may be open; registering, they come fixed by the option the user confirmed.
 */
final readonly class AiConsumptionDTO
{
    /** @param list<array{from_warehouse_id: int, quantity: string}> $transfers */
    public function __construct(
        public int $itemId,
        public int $batchId,
        public string $inputQuantity,
        public ?int $inputUnitId,
        public ?int $inputPresentationId,
        public string $occurredAt,
        public ?int $createdBy,
        public ?int $warehouseId = null,
        public ?int $headsTreated = null,
        public array $transfers = [],
        public ?string $notes = null,
        public ?string $confirmationToken = null,
        public bool $acknowledgeWarnings = false,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, ?int $createdBy): self
    {
        $notes = isset($data['notes']) ? trim((string) $data['notes']) : '';

        return new self(
            (int) $data['item_id'],
            (int) $data['batch_id'],
            (string) $data['input_quantity'],
            isset($data['input_unit_id']) ? (int) $data['input_unit_id'] : null,
            isset($data['input_presentation_id']) ? (int) $data['input_presentation_id'] : null,
            (string) $data['occurred_at'],
            $createdBy,
            isset($data['warehouse_id']) ? (int) $data['warehouse_id'] : null,
            isset($data['heads_treated']) ? (int) $data['heads_treated'] : null,
            array_map(fn (array $transfer) => [
                'from_warehouse_id' => (int) $transfer['from_warehouse_id'],
                'quantity' => (string) $transfer['quantity'],
            ], array_values($data['transfers'] ?? [])),
            $notes === '' ? null : $notes,
            isset($data['confirmation_token']) ? (string) $data['confirmation_token'] : null,
            (bool) ($data['acknowledge_warnings'] ?? false),
        );
    }

    /**
     * What a confirmation is signed over: everything the user saw that decides what gets written.
     * The notes stay out: they change nothing in the stock.
     *
     * @param list<array{from_warehouse_id: int, quantity: string}> $transfers
     * @return array<string, mixed>
     */
    public function facts(int $companyId, int $warehouseId, int $headsTreated, array $transfers): array
    {
        $moved = array_map(fn (array $t) => $t['from_warehouse_id'] . ':' . Decimal::of($t['quantity'])->toScale(4), $transfers);
        sort($moved);

        return [
            'company' => $companyId,
            'user' => $this->createdBy,
            'item' => $this->itemId,
            'batch' => $this->batchId,
            'quantity' => Decimal::of($this->inputQuantity)->toScale(4),
            'unit' => $this->inputUnitId,
            'presentation' => $this->inputPresentationId,
            'date' => $this->occurredAt,
            'warehouse' => $warehouseId,
            'heads' => $headsTreated,
            'transfers' => $moved,
        ];
    }
}
