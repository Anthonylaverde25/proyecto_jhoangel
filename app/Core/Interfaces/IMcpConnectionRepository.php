<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

use App\Core\Entities\McpConnectionEntity;

interface IMcpConnectionRepository
{
    /** @return list<McpConnectionEntity> newest first, revoked and expired included */
    public function findAllForOwner(int $userId, int $companyId): array;

    public function findForOwner(int $connectionId, int $userId, int $companyId): ?McpConnectionEntity;

    public function findById(int $connectionId): ?McpConnectionEntity;

    /** The live connection behind a Sanctum token, with user and company names loaded. */
    public function findActiveByAccessTokenId(int $personalAccessTokenId): ?McpConnectionEntity;

    public function findActiveByRefreshTokenHash(string $refreshTokenHash): ?McpConnectionEntity;

    /** @return list<McpConnectionEntity> live OAuth connections of this user and company for one client */
    public function findActiveOAuthForClient(int $userId, int $companyId, int $oauthClientId): array;

    public function countActiveManualForOwner(int $userId, int $companyId): int;

    public function save(McpConnectionEntity $connection, ?string $refreshTokenHash = null): McpConnectionEntity;

    /**
     * Points the connection to a new access token and refresh token (OAuth rotation), only if
     * the refresh token is still the expected one; false when a concurrent refresh won.
     */
    public function rotateCredentials(
        int $connectionId,
        string $expectedRefreshTokenHash,
        int $personalAccessTokenId,
        string $refreshTokenHash
    ): bool;

    public function markRevoked(int $connectionId, ?int $revokedByUserId): void;
}
