<?php

declare(strict_types=1);

namespace App\Application\DTOs\Mcp;

/**
 * The parameters of an OAuth authorization request (RFC 6749 §4.1.1 + PKCE + RFC 8707).
 */
final readonly class McpAuthorizationRequestDTO
{
    public function __construct(
        public string $responseType,
        public string $clientId,
        public string $redirectUri,
        public string $codeChallenge,
        public string $codeChallengeMethod,
        public ?string $scope,
        public ?string $state,
        public ?string $resource,
        /** The tenant host the client used for /oauth/authorize, carried through the consent screen. */
        public ?string $issuerHost = null,
    ) {
    }

    /**
     * @param array<string, mixed> $query
     */
    public static function fromArray(array $query): self
    {
        $text = static fn (string $key): ?string => isset($query[$key]) && is_string($query[$key]) && $query[$key] !== ''
            ? $query[$key]
            : null;

        return new self(
            responseType: $text('response_type') ?? '',
            clientId: $text('client_id') ?? '',
            redirectUri: $text('redirect_uri') ?? '',
            codeChallenge: $text('code_challenge') ?? '',
            codeChallengeMethod: $text('code_challenge_method') ?? '',
            scope: $text('scope'),
            state: $text('state'),
            resource: $text('resource'),
            issuerHost: $text('mcp_host'),
        );
    }

    public function withIssuerHost(string $host): self
    {
        return new self(
            $this->responseType,
            $this->clientId,
            $this->redirectUri,
            $this->codeChallenge,
            $this->codeChallengeMethod,
            $this->scope,
            $this->state,
            $this->resource,
            $host,
        );
    }

    /** @return array<string, string> the same request, to forward it to the consent screen */
    public function toQuery(): array
    {
        return array_filter([
            'response_type' => $this->responseType,
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'code_challenge' => $this->codeChallenge,
            'code_challenge_method' => $this->codeChallengeMethod,
            'scope' => $this->scope,
            'state' => $this->state,
            'resource' => $this->resource,
            'mcp_host' => $this->issuerHost,
        ], static fn (?string $value): bool => $value !== null && $value !== '');
    }
}
