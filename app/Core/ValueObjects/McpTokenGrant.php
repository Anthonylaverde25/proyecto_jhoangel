<?php

declare(strict_types=1);

namespace App\Core\ValueObjects;

/**
 * The OAuth token response handed to an AI client (RFC 6749 §5.1).
 */
final readonly class McpTokenGrant
{
    public function __construct(
        public string $accessToken,
        public int $expiresIn,
        public string $refreshToken,
        public string $scope,
    ) {
    }

    /** @return array<string, string|int> */
    public function toArray(): array
    {
        return [
            'access_token' => $this->accessToken,
            'token_type' => 'Bearer',
            'expires_in' => $this->expiresIn,
            'refresh_token' => $this->refreshToken,
            'scope' => $this->scope,
        ];
    }
}
