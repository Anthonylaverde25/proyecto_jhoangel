<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Core\Entities\McpConnectionEntity;
use App\Core\Interfaces\IMcpConnectionRepository;
use App\Core\Interfaces\IMcpTokenGateway;

/**
 * Cuts a connection: deletes its Sanctum token, so the very next read of the AI client is a
 * 401, and keeps the row (revoked) for audit. Idempotent.
 */
final class McpConnectionRevoker
{
    public function __construct(
        private readonly IMcpConnectionRepository $connections,
        private readonly IMcpTokenGateway $tokens,
    ) {
    }

    public function __invoke(McpConnectionEntity $connection, ?int $revokedByUserId): void
    {
        if ($connection->getId() === null || $connection->isRevoked()) {
            return;
        }

        $this->tokens->revoke($connection->getPersonalAccessTokenId());
        $this->connections->markRevoked($connection->getId(), $revokedByUserId);
    }
}
