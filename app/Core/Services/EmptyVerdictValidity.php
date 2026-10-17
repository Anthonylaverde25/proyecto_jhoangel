<?php

declare(strict_types=1);

namespace App\Core\Services;

/**
 * An EMPTY verdict only holds if it was given once the female stopped sharing the paddock with
 * the bulls: one scanned empty while still exposed may conceive afterwards.
 *
 * The exposure end is her recorded `exposed_until`; while it is still open, the day the exposure
 * is about to end (the closing day of the order) can be passed instead.
 */
final class EmptyVerdictValidity
{
    public static function isFinal(?string $diagnosedAt, ?string $exposedUntil, ?string $exposureEndsOn = null): bool
    {
        if ($diagnosedAt === null) {
            return false;
        }

        $end = $exposedUntil ?? $exposureEndsOn;
        if ($end === null) {
            return false;
        }

        return substr($diagnosedAt, 0, 10) >= substr($end, 0, 10);
    }
}
