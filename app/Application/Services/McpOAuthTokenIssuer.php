<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Core\Interfaces\IMcpTokenGateway;
use App\Core\ValueObjects\IssuedMcpToken;
use DateTimeImmutable;

/**
 * Mints the short-lived access token of an OAuth connection plus a fresh refresh token.
 * The access token never outlives the connection it belongs to.
 */
final class McpOAuthTokenIssuer
{
    public function __construct(private readonly IMcpTokenGateway $tokens)
    {
    }

    /** @return array{0: IssuedMcpToken, 1: string, 2: int} access token, plaintext refresh token, expires_in seconds */
    public function __invoke(int $userId, string $name, DateTimeImmutable $connectionExpiresAt): array
    {
        $now = new DateTimeImmutable();
        $ttlMinutes = (int) config('livestock.mcp.oauth.access_token_ttl_minutes', 60);
        $expiresAt = min($now->modify("+{$ttlMinutes} minutes"), $connectionExpiresAt);

        $issued = $this->tokens->issue($userId, $name, $expiresAt);

        return [$issued, bin2hex(random_bytes(32)), max(1, $expiresAt->getTimestamp() - $now->getTimestamp())];
    }
}
