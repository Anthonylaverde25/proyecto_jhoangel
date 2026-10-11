<?php

declare(strict_types=1);

namespace App\Application\DTOs\EntryOrders;

/**
 * "Dar de baja pendientes": head received without caravan on a DTE that will never get one, in the
 * batch they went to, and why.
 */
final readonly class WriteOffPendingHeadsDTO
{
    public function __construct(
        public int $dteId,
        public int $batchId,
        public int $headCount,
        public string $reason
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            dteId: (int) $data['dte_id'],
            batchId: (int) $data['batch_id'],
            headCount: (int) $data['head_count'],
            reason: trim((string) $data['reason'])
        );
    }
}
