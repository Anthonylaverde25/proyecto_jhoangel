<?php

declare(strict_types=1);

namespace App\Application\UseCases\Mcp;

use App\Application\Services\McpOAuthTokenIssuer;
use App\Core\Exceptions\McpOAuthException;
use App\Core\Interfaces\IMcpConnectionRepository;
use App\Core\Interfaces\IMcpOAuthClientRepository;
use App\Core\Interfaces\IMcpTokenGateway;
use App\Core\ValueObjects\McpTokenGrant;
use Illuminate\Support\Facades\DB;

/**
 * `grant_type=refresh_token`: a new access token for a live connection.
 *
 * Refresh tokens rotate (OAuth 2.1 §4.3.1 for public clients): each use returns a new one and
 * the old one stops working. The previous access token is deleted at the same time.
 */
final class RefreshMcpAccessTokenUseCase
{
    public function __construct(
        private readonly IMcpOAuthClientRepository $clients,
        private readonly IMcpConnectionRepository $connections,
        private readonly IMcpTokenGateway $tokens,
        private readonly McpOAuthTokenIssuer $issueTokens,
    ) {
    }

    /**
     * @throws McpOAuthException
     */
    public function __invoke(string $clientId, string $refreshToken): McpTokenGrant
    {
        $client = $this->clients->findByClientId($clientId);
        if ($client === null) {
            throw McpOAuthException::invalidClient();
        }

        if ($refreshToken === '') {
            throw McpOAuthException::invalidRequest('refresh_token is required.');
        }

        $connection = $this->connections->findActiveByRefreshTokenHash(hash('sha256', $refreshToken));
        if ($connection === null || $connection->getOAuthClientId() !== $client->getId() || !$connection->isActive()) {
            throw McpOAuthException::invalidGrant('The refresh token is not valid.');
        }

        return DB::transaction(function () use ($connection, $refreshToken): McpTokenGrant {
            $this->tokens->revoke($connection->getPersonalAccessTokenId());

            [$access, $newRefreshToken, $expiresIn] = ($this->issueTokens)(
                $connection->getUserId(),
                $connection->getName(),
                $connection->getExpiresAt()
            );

            $rotated = $this->connections->rotateCredentials(
                (int) $connection->getId(),
                hash('sha256', $refreshToken),
                $access->personalAccessTokenId,
                hash('sha256', $newRefreshToken)
            );

            if (!$rotated) {
                // Rolls back the token minted above: the refresh token was spent meanwhile.
                throw McpOAuthException::invalidGrant('The refresh token is not valid.');
            }

            return new McpTokenGrant($access->plainTextToken, $expiresIn, $newRefreshToken, IMcpTokenGateway::ABILITY);
        });
    }
}
