<?php

declare(strict_types=1);

namespace App\Application\DTOs;

final class BulkTransferCaravansDTO
{
    /**
     * @param int[] $caravanIds
     */
    public function __construct(
        public readonly array $caravanIds,
        public readonly ?int $targetBatchId = null,
        public readonly ?string $reason = null,
        public readonly ?string $movementDate = null
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            caravanIds: array_map('intval', (array) ($data['caravan_ids'] ?? [])),
            targetBatchId: isset($data['target_batch_id']) ? (int) $data['target_batch_id'] : null,
            reason: isset($data['reason']) ? (string) $data['reason'] : null,
            movementDate: isset($data['movement_date']) ? (string) $data['movement_date'] : null
        );
    }
}
