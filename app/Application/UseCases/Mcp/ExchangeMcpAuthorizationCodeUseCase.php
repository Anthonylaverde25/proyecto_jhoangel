<?php

declare(strict_types=1);

namespace App\Application\UseCases\Mcp;

use App\Application\DTOs\Mcp\ExchangeMcpAuthorizationCodeDTO;
use App\Application\Services\McpConnectionRevoker;
use App\Application\Services\McpOAuthTokenIssuer;
use App\Application\Services\McpPublicUrls;
use App\Core\Entities\McpConnectionEntity;
use App\Core\Exceptions\McpOAuthException;
use App\Core\Interfaces\IMcpAuthorizationCodeRepository;
use App\Core\Interfaces\IMcpConnectionRepository;
use App\Core\Interfaces\IMcpOAuthClientRepository;
use App\Core\ValueObjects\McpTokenGrant;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * `grant_type=authorization_code`: the AI client trades the approved code for its tokens.
 *
 * One OAuth connection per user, company and client: approving the same client again replaces
 * the previous connection instead of piling up live tokens.
 */
final class ExchangeMcpAuthorizationCodeUseCase
{
    public function __construct(
        private readonly IMcpOAuthClientRepository $clients,
        private readonly IMcpAuthorizationCodeRepository $codes,
        private readonly IMcpConnectionRepository $connections,
        private readonly McpConnectionRevoker $revoke,
        private readonly McpOAuthTokenIssuer $issueTokens,
    ) {
    }

    /**
     * @throws McpOAuthException
     */
    public function __invoke(ExchangeMcpAuthorizationCodeDTO $dto, string $hostname): McpTokenGrant
    {
        $client = $this->clients->findByClientId($dto->clientId);
        if ($client === null) {
            throw McpOAuthException::invalidClient();
        }

        if ($dto->code === '' || $dto->codeVerifier === '' || $dto->redirectUri === '') {
            throw McpOAuthException::invalidRequest('code, redirect_uri and code_verifier are required.');
        }

        $code = $this->codes->findByCodeHash(hash('sha256', $dto->code));
        if ($code === null || $code->getOAuthClientId() !== $client->getId()) {
            throw McpOAuthException::invalidGrant('The authorization code is not valid.');
        }

        if ($code->isUsed()) {
            // A replayed code means it leaked: cut whatever it produced (RFC 6749 §4.1.2).
            $this->revokeConnection($code->getConnectionId());
            throw McpOAuthException::invalidGrant('The authorization code was already used.');
        }

        if ($code->isExpired()) {
            throw McpOAuthException::invalidGrant('The authorization code expired.');
        }

        if ($code->getRedirectUri() !== $dto->redirectUri) {
            throw McpOAuthException::invalidGrant('redirect_uri does not match the authorization request.');
        }

        if (!$code->verifiesCodeVerifier($dto->codeVerifier)) {
            throw McpOAuthException::invalidGrant('PKCE verification failed.');
        }

        if ($dto->resource !== null && !McpPublicUrls::sameResource($dto->resource, McpPublicUrls::resource($hostname))) {
            throw McpOAuthException::invalidRequest('The requested resource is not served by this authorization server.');
        }

        return DB::transaction(function () use ($code, $client): McpTokenGrant {
            if (!$this->codes->consume((int) $code->getId())) {
                throw McpOAuthException::invalidGrant('The authorization code was already used.');
            }

            foreach ($this->connections->findActiveOAuthForClient($code->getUserId(), $code->getCompanyId(), $client->getId()) as $previous) {
                ($this->revoke)($previous, $code->getUserId());
            }

            $ttlDays = (int) config('livestock.mcp.oauth.connection_ttl_days', 90);
            $connectionExpiresAt = (new DateTimeImmutable())->modify("+{$ttlDays} days");

            [$access, $refreshToken, $expiresIn] = ($this->issueTokens)($code->getUserId(), $client->getClientName(), $connectionExpiresAt);

            $connection = $this->connections->save(McpConnectionEntity::oauth(
                userId: $code->getUserId(),
                companyId: $code->getCompanyId(),
                clientName: $client->getClientName(),
                expiresAt: $connectionExpiresAt,
                tokenId: $access->personalAccessTokenId,
                oauthClientId: $client->getId(),
            ), hash('sha256', $refreshToken));

            $this->codes->attachConnection((int) $code->getId(), (int) $connection->getId());

            return new McpTokenGrant($access->plainTextToken, $expiresIn, $refreshToken, $code->getScope());
        });
    }

    private function revokeConnection(?int $connectionId): void
    {
        $connection = $connectionId !== null ? $this->connections->findById($connectionId) : null;
        if ($connection !== null) {
            ($this->revoke)($connection, null);
        }
    }
}
