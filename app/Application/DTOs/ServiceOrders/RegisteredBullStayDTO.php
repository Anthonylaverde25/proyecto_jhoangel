<?php

declare(strict_types=1);

namespace App\Application\DTOs\ServiceOrders;

/** The trip of a bull through a service that already ended: where it came from and where it went. */
final readonly class RegisteredBullStayDTO
{
    public function __construct(
        public int $maleCaravanId,
        public int $originBatchId,
        public string $joinedAt,
        public string $retiredAt,
        public int $destinationBatchId
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            maleCaravanId: (int) $data['male_caravan_id'],
            originBatchId: (int) $data['origin_batch_id'],
            joinedAt: substr((string) $data['joined_at'], 0, 10),
            retiredAt: substr((string) $data['retired_at'], 0, 10),
            destinationBatchId: (int) $data['destination_batch_id']
        );
    }
}
