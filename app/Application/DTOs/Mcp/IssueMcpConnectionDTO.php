<?php

declare(strict_types=1);

namespace App\Application\DTOs\Mcp;

/**
 * Request to connect an AI client by hand: the user copies the token into the client.
 */
final readonly class IssueMcpConnectionDTO
{
    public function __construct(
        public int $userId,
        public int $companyId,
        public string $name,
        public int $ttlDays,
    ) {
    }
}
