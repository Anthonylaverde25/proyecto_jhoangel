<?php

declare(strict_types=1);

namespace App\Core\ValueObjects;

use DateTimeImmutable;

/**
 * A freshly minted MCP access token. The plaintext exists only in the request that mints it.
 */
final readonly class IssuedMcpToken
{
    public function __construct(
        public int $personalAccessTokenId,
        public string $plainTextToken,
        public DateTimeImmutable $expiresAt,
    ) {
    }
}
