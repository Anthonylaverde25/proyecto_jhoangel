<?php

declare(strict_types=1);

namespace App\Application\UseCases\Mcp;

use App\Core\Entities\McpOAuthClientEntity;
use App\Core\Exceptions\McpOAuthException;
use App\Core\Interfaces\IMcpOAuthClientRepository;

/**
 * Dynamic Client Registration (RFC 7591): how Claude, Gemini or any MCP client introduces itself
 * before asking the user for access. Registration grants nothing by itself; access still
 * requires the user to log in and approve on the consent screen.
 *
 * Every client is public (`token_endpoint_auth_method=none`): no secret is issued and PKCE
 * protects the code. A client asking for a secret gets `none` back, as RFC 7591 §3.2.1 allows.
 */
final class RegisterMcpOAuthClientUseCase
{
    private const DEFAULT_NAME = 'Cliente de IA';

    public function __construct(private readonly IMcpOAuthClientRepository $clients)
    {
    }

    /**
     * @param array<string, mixed> $metadata
     *
     * @throws McpOAuthException
     */
    public function __invoke(array $metadata): McpOAuthClientEntity
    {
        $redirectUris = $metadata['redirect_uris'] ?? null;
        $max = (int) config('livestock.mcp.oauth.max_redirect_uris', 10);

        if (!is_array($redirectUris) || $redirectUris === [] || count($redirectUris) > $max) {
            throw McpOAuthException::invalidRedirectUri("Between 1 and {$max} redirect_uris are required.");
        }

        foreach ($redirectUris as $uri) {
            if (!is_string($uri) || !self::isAcceptableRedirectUri($uri)) {
                throw McpOAuthException::invalidRedirectUri('Redirect URIs must use https, an http loopback address or a private-use scheme, without fragment.');
            }
        }

        $grantTypes = $metadata['grant_types'] ?? ['authorization_code'];
        if (!is_array($grantTypes) || !in_array('authorization_code', $grantTypes, true)) {
            throw McpOAuthException::invalidClientMetadata('grant_types must include authorization_code.');
        }

        $name = is_string($metadata['client_name'] ?? null) && trim($metadata['client_name']) !== ''
            ? mb_substr(trim($metadata['client_name']), 0, 150)
            : self::DEFAULT_NAME;

        return $this->clients->register('mcp_' . bin2hex(random_bytes(16)), $name, array_values($redirectUris));
    }

    /**
     * OAuth 2.1 §2.3.1 and RFC 8252: https for web clients, http only on loopback for native
     * clients (Gemini CLI, Claude Code), or a private-use scheme in reverse-domain form.
     */
    public static function isAcceptableRedirectUri(string $uri): bool
    {
        $parts = parse_url($uri);
        if ($parts === false || !isset($parts['scheme']) || isset($parts['fragment'])) {
            return false;
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower(trim($parts['host'] ?? '', '[]'));

        return match (true) {
            $scheme === 'https' => $host !== '',
            $scheme === 'http' => in_array($host, ['localhost', '127.0.0.1', '::1'], true),
            default => str_contains($scheme, '.'),
        };
    }
}
