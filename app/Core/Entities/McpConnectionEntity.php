<?php

declare(strict_types=1);

namespace App\Core\Entities;

use DateTimeImmutable;

/**
 * An AI client connected through MCP on behalf of a user, pinned to one company.
 *
 * It never carries the secret: the access token lives in Sanctum and only its hash is stored;
 * the refresh token (OAuth connections) is stored as a SHA-256 hash as well.
 */
final class McpConnectionEntity
{
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_OAUTH = 'oauth';

    public function __construct(
        private readonly ?int $id,
        private readonly int $userId,
        private readonly int $companyId,
        private readonly string $source,
        private readonly string $name,
        private readonly DateTimeImmutable $expiresAt,
        private readonly ?int $personalAccessTokenId = null,
        private readonly ?int $oauthClientId = null,
        private readonly ?DateTimeImmutable $revokedAt = null,
        private readonly ?DateTimeImmutable $createdAt = null,
        private readonly ?DateTimeImmutable $lastUsedAt = null,
        private readonly ?string $userName = null,
        private readonly ?string $companyName = null,
    ) {
    }

    public static function manual(int $userId, int $companyId, string $name, DateTimeImmutable $expiresAt, int $tokenId): self
    {
        return new self(null, $userId, $companyId, self::SOURCE_MANUAL, $name, $expiresAt, $tokenId);
    }

    public static function oauth(
        int $userId,
        int $companyId,
        string $clientName,
        DateTimeImmutable $expiresAt,
        int $tokenId,
        int $oauthClientId
    ): self {
        return new self(null, $userId, $companyId, self::SOURCE_OAUTH, $clientName, $expiresAt, $tokenId, $oauthClientId);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getCompanyId(): int
    {
        return $this->companyId;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getExpiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getPersonalAccessTokenId(): ?int
    {
        return $this->personalAccessTokenId;
    }

    public function getOAuthClientId(): ?int
    {
        return $this->oauthClientId;
    }

    public function getRevokedAt(): ?DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function getCreatedAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastUsedAt(): ?DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function getUserName(): ?string
    {
        return $this->userName;
    }

    public function getCompanyName(): ?string
    {
        return $this->companyName;
    }

    public function isRevoked(): bool
    {
        return $this->revokedAt !== null;
    }

    public function isExpired(?DateTimeImmutable $now = null): bool
    {
        return $this->expiresAt <= ($now ?? new DateTimeImmutable());
    }

    public function isActive(?DateTimeImmutable $now = null): bool
    {
        return !$this->isRevoked() && !$this->isExpired($now);
    }
}
