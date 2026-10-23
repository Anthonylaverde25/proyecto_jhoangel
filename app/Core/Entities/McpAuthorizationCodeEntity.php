<?php

declare(strict_types=1);

namespace App\Core\Entities;

use DateTimeImmutable;

/**
 * What the user approved on the consent screen, waiting to be exchanged for tokens.
 */
final class McpAuthorizationCodeEntity
{
    public function __construct(
        private readonly ?int $id,
        private readonly int $oauthClientId,
        private readonly int $userId,
        private readonly int $companyId,
        private readonly string $redirectUri,
        private readonly string $codeChallenge,
        private readonly string $scope,
        private readonly ?string $resource,
        private readonly DateTimeImmutable $expiresAt,
        private readonly ?DateTimeImmutable $usedAt = null,
        private readonly ?int $connectionId = null,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOAuthClientId(): int
    {
        return $this->oauthClientId;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getCompanyId(): int
    {
        return $this->companyId;
    }

    public function getRedirectUri(): string
    {
        return $this->redirectUri;
    }

    public function getCodeChallenge(): string
    {
        return $this->codeChallenge;
    }

    public function getScope(): string
    {
        return $this->scope;
    }

    public function getResource(): ?string
    {
        return $this->resource;
    }

    public function getExpiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getConnectionId(): ?int
    {
        return $this->connectionId;
    }

    public function isUsed(): bool
    {
        return $this->usedAt !== null;
    }

    public function isExpired(?DateTimeImmutable $now = null): bool
    {
        return $this->expiresAt <= ($now ?? new DateTimeImmutable());
    }

    /** PKCE S256 (RFC 7636): BASE64URL(SHA256(code_verifier)) must equal the stored challenge. */
    public function verifiesCodeVerifier(string $codeVerifier): bool
    {
        $computed = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        return hash_equals($this->codeChallenge, $computed);
    }
}
