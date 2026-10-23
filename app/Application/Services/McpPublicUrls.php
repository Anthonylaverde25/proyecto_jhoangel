<?php

declare(strict_types=1);

namespace App\Application\Services;

/**
 * Public URLs of the MCP integration for one tenant. Every template carries `{hostname}`, the
 * tenant domain without port, because each tenant is its own MCP resource and its own OAuth
 * issuer: a token minted on one tenant must never be accepted by another.
 */
final class McpPublicUrls
{
    /** The MCP endpoint an AI client connects to (served by ai-agent). */
    public static function resource(string $hostname): string
    {
        return self::fill((string) config('livestock.mcp.public_url'), $hostname);
    }

    /** The OAuth authorization server identifier (this API). */
    public static function issuer(string $hostname): string
    {
        return rtrim(self::fill((string) config('livestock.mcp.issuer_url'), $hostname), '/');
    }

    /** The SPA screen where the user approves an AI client. */
    public static function consent(string $hostname): string
    {
        return self::fill((string) config('livestock.mcp.consent_url'), $hostname);
    }

    /**
     * The host to show the user in /settings. MCP_PUBLIC_HOSTNAME overrides it in development, when
     * the SPA talks to localhost but AI clients reach the tenant through a public tunnel.
     */
    public static function displayHostname(string $requestHostname): string
    {
        $override = config('livestock.mcp.public_hostname');

        return is_string($override) && $override !== '' ? $override : $requestHostname;
    }

    /** RFC 8707 resource indicators are compared without a trailing slash. */
    public static function sameResource(string $a, string $b): bool
    {
        return rtrim($a, '/') === rtrim($b, '/');
    }

    private static function fill(string $template, string $hostname): string
    {
        return str_replace('{hostname}', $hostname, $template);
    }
}
