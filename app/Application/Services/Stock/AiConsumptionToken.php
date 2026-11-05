<?php

declare(strict_types=1);

namespace App\Application\Services\Stock;

/**
 * The confirmation a preview hands out and a registration demands: "{expiry}.{nonce}.{signature}",
 * nothing stored. It proves the preview happened and that the data did not change since, and it doubles
 * as the idempotency key, which fits in 64 characters. It does not prove the user said yes.
 */
final class AiConsumptionToken
{
    private const TTL_SECONDS = 1800;

    /** @param array<string, mixed> $facts */
    public function issue(array $facts): string
    {
        $expiry = base_convert((string) (now()->getTimestamp() + self::TTL_SECONDS), 10, 36);
        $nonce = bin2hex(random_bytes(6));

        return "{$expiry}.{$nonce}." . $this->sign($expiry, $nonce, $facts);
    }

    /** @param array<string, mixed> $facts */
    public function matches(string $token, array $facts): bool
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return false;
        }

        return hash_equals($this->sign($parts[0], $parts[1], $facts), $parts[2]);
    }

    public function isExpired(string $token): bool
    {
        return (int) base_convert(explode('.', $token)[0], 36, 10) < now()->getTimestamp();
    }

    /** @param array<string, mixed> $facts */
    private function sign(string $expiry, string $nonce, array $facts): string
    {
        $payload = $expiry . '|' . $nonce . '|' . json_encode($facts, JSON_THROW_ON_ERROR);

        return substr(hash_hmac('sha256', $payload, (string) config('app.key')), 0, 40);
    }
}
