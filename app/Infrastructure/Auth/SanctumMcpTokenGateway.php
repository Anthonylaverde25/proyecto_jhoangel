<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth;

use App\Core\Interfaces\IMcpTokenGateway;
use App\Core\ValueObjects\IssuedMcpToken;
use App\Models\User;
use DateTimeImmutable;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * MCP access tokens are Sanctum personal access tokens of the user with the single ability
 * `mcp:read`. Being ordinary Sanctum tokens, every existing endpoint authenticates them with
 * no extra code, and RestrictMcpTokenScope narrows what they may do.
 */
final class SanctumMcpTokenGateway implements IMcpTokenGateway
{
    public function issue(int $userId, string $name, DateTimeImmutable $expiresAt): IssuedMcpToken
    {
        $user = User::query()->findOrFail($userId);
        $newToken = $user->createToken($name, [self::ABILITY], \DateTime::createFromImmutable($expiresAt));

        return new IssuedMcpToken((int) $newToken->accessToken->getKey(), $newToken->plainTextToken, $expiresAt);
    }

    public function revoke(?int $personalAccessTokenId): void
    {
        if ($personalAccessTokenId === null) {
            return;
        }

        PersonalAccessToken::query()->whereKey($personalAccessTokenId)->delete();
    }
}
