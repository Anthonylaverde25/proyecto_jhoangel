<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * Where one female of a birth order stands.
 *
 * - PENDING: she did not calve yet and nothing was reported.
 * - OVERDUE: she passed her due date without calving (an N on the sheet). Still open: it is an
 *   alert of a female at risk, not an outcome.
 * - BORN: she calved a live calf.
 * - BORN_DIED: she calved a live calf that died at foot. Her gestation closed successful.
 * - LOST: the pregnancy was lost — a calf born dead, or a loss registered outside the sheet.
 * - SKIPPED: what an open line becomes when the order is closed incomplete or cancelled. Her
 *   gestation stays open, and so does her overdue alert: closing an order is not declaring a loss.
 */
enum BirthOrderAnimalStatus: string
{
    case PENDING = 'PENDING';
    case OVERDUE = 'OVERDUE';
    case BORN = 'BORN';
    case BORN_DIED = 'BORN_DIED';
    case LOST = 'LOST';
    case SKIPPED = 'SKIPPED';

    public static function forOutcome(BirthOutcome $outcome): self
    {
        return match ($outcome) {
            BirthOutcome::LIVE => self::BORN,
            BirthOutcome::PERINATAL_DEATH => self::BORN_DIED,
            default => self::LOST,
        };
    }

    /**
     * Still waiting for the calving: the order cannot be executed while one is left.
     */
    public function isOpen(): bool
    {
        return $this === self::PENDING || $this === self::OVERDUE;
    }

    /**
     * What happened to her is already registered.
     */
    public function isResolved(): bool
    {
        return $this === self::BORN || $this === self::BORN_DIED || $this === self::LOST;
    }

    /**
     * @return list<string>
     */
    public static function openValues(): array
    {
        return [self::PENDING->value, self::OVERDUE->value];
    }
}
