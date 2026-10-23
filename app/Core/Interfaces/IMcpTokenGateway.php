<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

use App\Core\ValueObjects\IssuedMcpToken;
use DateTimeImmutable;

/**
 * Mints and destroys the access tokens AI clients present. They are ordinary Sanctum tokens of
 * the user, restricted to the single ability `mcp:read`.
 */
interface IMcpTokenGateway
{
    public const ABILITY = 'mcp:read';

    public function issue(int $userId, string $name, DateTimeImmutable $expiresAt): IssuedMcpToken;

    public function revoke(?int $personalAccessTokenId): void;
}
