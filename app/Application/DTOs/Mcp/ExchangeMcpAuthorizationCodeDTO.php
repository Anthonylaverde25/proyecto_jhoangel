<?php

declare(strict_types=1);

namespace App\Application\DTOs\Mcp;

/**
 * Token request with `grant_type=authorization_code` (RFC 6749 §4.1.3 + PKCE).
 */
final readonly class ExchangeMcpAuthorizationCodeDTO
{
    public function __construct(
        public string $clientId,
        public string $code,
        public string $redirectUri,
        public string $codeVerifier,
        public ?string $resource,
    ) {
    }
}
