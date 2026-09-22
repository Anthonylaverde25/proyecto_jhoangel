<?php

declare(strict_types=1);

namespace App\Application\DTOs;

final class BulkTransferCaravansDTO
{
    /**
     * @param int[] $caravanIds
     * @param array<string, mixed>|null $newBatch Mutually exclusive with $targetBatchId:
     *        { name, activity_id, batch_type_id, is_confined, farm_id? }. The destination
     *        batch is created inside the same transaction as the transfer.
     */
    public function __construct(
        public readonly array $caravanIds,
        public readonly ?int $targetBatchId = null,
        public readonly ?string $reason = null,
        public readonly ?string $movementDate = null,
        public readonly ?array $newBatch = null
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $newBatch = isset($data['new_batch']) && is_array($data['new_batch']) && $data['new_batch'] !== []
            ? $data['new_batch']
            : null;

        return new self(
            caravanIds: array_map('intval', (array) ($data['caravan_ids'] ?? [])),
            targetBatchId: isset($data['target_batch_id']) ? (int) $data['target_batch_id'] : null,
            reason: isset($data['reason']) ? (string) $data['reason'] : null,
            movementDate: isset($data['movement_date']) ? (string) $data['movement_date'] : null,
            newBatch: $newBatch
        );
    }
}
