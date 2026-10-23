<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

use RuntimeException;

/**
 * An OAuth protocol error. Not a DomainException: the OAuth endpoints answer with the RFC 6749
 * shape (`error`, `error_description`) instead of the API's 422 envelope.
 */
final class McpOAuthException extends RuntimeException
{
    public function __construct(
        private readonly string $error,
        string $description,
        private readonly int $status = 400,
    ) {
        parent::__construct($description);
    }

    public static function invalidRequest(string $description): self
    {
        return new self('invalid_request', $description);
    }

    public static function invalidClient(string $description = 'Unknown client.'): self
    {
        return new self('invalid_client', $description, 401);
    }

    public static function invalidGrant(string $description): self
    {
        return new self('invalid_grant', $description);
    }

    public static function unsupportedGrantType(string $grantType): self
    {
        return new self('unsupported_grant_type', "Grant type '{$grantType}' is not supported.");
    }

    public static function invalidScope(string $description): self
    {
        return new self('invalid_scope', $description);
    }

    public static function invalidRedirectUri(string $description): self
    {
        return new self('invalid_redirect_uri', $description);
    }

    public static function invalidClientMetadata(string $description): self
    {
        return new self('invalid_client_metadata', $description);
    }

    public function getError(): string
    {
        return $this->error;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    /** @return array{error: string, error_description: string} */
    public function toArray(): array
    {
        return ['error' => $this->error, 'error_description' => $this->getMessage()];
    }
}
