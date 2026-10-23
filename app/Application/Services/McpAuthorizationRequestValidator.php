<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Application\DTOs\Mcp\McpAuthorizationRequestDTO;
use App\Core\Entities\McpOAuthClientEntity;
use App\Core\Exceptions\McpOAuthException;
use App\Core\Interfaces\IMcpOAuthClientRepository;
use App\Core\Interfaces\IMcpTokenGateway;
use App\Core\Interfaces\ITenantDomains;

/**
 * Checks an authorization request before anything is shown to the user or a code is minted.
 *
 * Client and redirect URI are checked first: while either is unknown, errors must NOT be sent
 * to the redirect URI (it could belong to an attacker), so the caller shows them instead.
 */
final class McpAuthorizationRequestValidator
{
    /** RFC 7636: 43 to 128 characters of the unreserved set. */
    private const CODE_CHALLENGE_PATTERN = '/^[A-Za-z0-9\-._~]{43,128}$/';

    public function __construct(
        private readonly IMcpOAuthClientRepository $clients,
        private readonly ITenantDomains $tenantDomains,
    ) {
    }

    /**
     * The tenant domain the AI client is talking to. The consent screen may reach this API through
     * another domain of the same tenant (the SPA's own API host), so the domain the client used is
     * taken from its `resource` or, when it sends none (Gemini), from the host /oauth/authorize
     * was called on. Either is trusted only if it is a domain of this tenant.
     */
    public function resolveHostname(McpAuthorizationRequestDTO $request, string $requestHostname): string
    {
        $candidates = [
            $request->resource !== null ? parse_url($request->resource, PHP_URL_HOST) : null,
            $request->issuerHost,
        ];

        foreach ($candidates as $host) {
            if (is_string($host) && $host !== '' && $this->tenantDomains->servesHostname($host)) {
                return strtolower($host);
            }
        }

        return $requestHostname;
    }

    /**
     * @throws McpOAuthException
     */
    public function __invoke(McpAuthorizationRequestDTO $request, string $hostname): McpOAuthClientEntity
    {
        $client = $this->clients->findByClientId($request->clientId);
        if ($client === null) {
            throw McpOAuthException::invalidClient('El cliente de IA no está registrado en este establecimiento.');
        }

        if (!$client->allowsRedirectUri($request->redirectUri)) {
            throw McpOAuthException::invalidRedirectUri('La dirección de retorno no coincide con la registrada por el cliente.');
        }

        if ($request->responseType !== 'code') {
            throw new McpOAuthException('unsupported_response_type', 'Only response_type=code is supported.');
        }

        if ($request->codeChallengeMethod !== 'S256' || preg_match(self::CODE_CHALLENGE_PATTERN, $request->codeChallenge) !== 1) {
            throw McpOAuthException::invalidRequest('PKCE with code_challenge_method=S256 is required.');
        }

        $this->assertScope($request->scope);

        $hostname = $this->resolveHostname($request, $hostname);
        if ($request->resource !== null && !McpPublicUrls::sameResource($request->resource, McpPublicUrls::resource($hostname))) {
            throw McpOAuthException::invalidRequest('The requested resource is not served by this authorization server.');
        }

        return $client;
    }

    /** Errors raised after the client and redirect URI are trusted can go back to the client. */
    public static function isRedirectable(McpOAuthException $exception): bool
    {
        return !in_array($exception->getError(), ['invalid_client', 'invalid_redirect_uri'], true);
    }

    /**
     * Only `mcp:read` is ever issued. Other requested scopes (openid, profile...) are ignored, as
     * RFC 6749 §3.3 allows, instead of failing clients that ask for more than they need; the token
     * response states the granted scope. Asking for nothing usable at all is still an error.
     */
    private function assertScope(?string $scope): void
    {
        if ($scope === null || trim($scope) === '') {
            return;
        }

        $requested = preg_split('/\s+/', trim($scope)) ?: [];
        if (!in_array(IMcpTokenGateway::ABILITY, $requested, true) && array_diff($requested, ['openid', 'profile', 'email', 'offline_access']) !== []) {
            throw McpOAuthException::invalidScope('Only mcp:read is offered.');
        }
    }
}
