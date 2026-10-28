<?php

declare(strict_types=1);

namespace App\Core\Entities;

/** One step of an exit order's history: the status change, who did it, why, and what it carried. */
final class ExitOrderHistoryEntity
{
    /** @param array<string, mixed>|null $metadata */
    public function __construct(
        public readonly ?int $id,
        public readonly ?string $fromStatus,
        public readonly string $toStatus,
        public readonly ?int $actionUserId,
        public readonly ?string $actionUserName,
        public readonly ?string $reason,
        public readonly ?array $metadata,
        public readonly ?\DateTimeInterface $createdAt,
    ) {
    }
}
