<?php

declare(strict_types=1);

namespace App\Core\Entities;

/**
 * An AI product registered as a public OAuth client (Dynamic Client Registration, RFC 7591).
 */
final class McpOAuthClientEntity
{
    /**
     * @param list<string> $redirectUris
     */
    public function __construct(
        private readonly int $id,
        private readonly string $clientId,
        private readonly string $clientName,
        private readonly array $redirectUris,
    ) {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getClientId(): string
    {
        return $this->clientId;
    }

    public function getClientName(): string
    {
        return $this->clientName;
    }

    /** @return list<string> */
    public function getRedirectUris(): array
    {
        return $this->redirectUris;
    }

    /** Exact string match, as OAuth 2.1 requires: no prefix or wildcard matching. */
    public function allowsRedirectUri(string $redirectUri): bool
    {
        return in_array($redirectUri, $this->redirectUris, true);
    }
}
