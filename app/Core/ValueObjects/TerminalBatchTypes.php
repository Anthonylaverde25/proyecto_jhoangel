<?php

declare(strict_types=1);

namespace App\Core\ValueObjects;

/**
 * Batch types an animal never comes back from: the death batch and the exit batch. Nothing is
 * consumed, weaned, transferred or created by hand in them, and an animal in one is not livestock
 * of the company any more. One list, so a new terminal type is added once.
 */
final class TerminalBatchTypes
{
    public const DEATH = 'INTERNAL_DEATH';
    public const EXIT = 'INTERNAL_EXIT';

    public const ALL = [self::DEATH, self::EXIT];

    public static function contains(?string $batchTypeCode): bool
    {
        return $batchTypeCode !== null && in_array($batchTypeCode, self::ALL, true);
    }
}
