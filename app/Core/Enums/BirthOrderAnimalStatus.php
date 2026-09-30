<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * Where one female of a birth order stands. BORN: she calved a live calf. LOST: the pregnancy ended
 * in a stillbirth or an abortion. SKIPPED is what a female still PENDING becomes when the order is
 * closed incomplete or cancelled — her gestation stays open: closing an order is not declaring a loss.
 */
enum BirthOrderAnimalStatus: string
{
    case PENDING = 'PENDING';
    case BORN = 'BORN';
    case LOST = 'LOST';
    case SKIPPED = 'SKIPPED';

    public static function forOutcome(BirthOutcome $outcome): self
    {
        return $outcome === BirthOutcome::LIVE ? self::BORN : self::LOST;
    }
}
