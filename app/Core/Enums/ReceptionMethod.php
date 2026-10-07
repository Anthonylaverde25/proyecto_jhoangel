<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * How a caravan was received: written down by hand from the order, or on an ING-03 receipt sheet
 * and scanned. CHUTE (read by an electronic reader) is kept for the rows already recorded with it;
 * a reader's file will fill in the ING-03, so no reception takes it today.
 */
enum ReceptionMethod: string
{
    case CHUTE = 'CHUTE';
    case MANUAL = 'MANUAL';
    case SHEET = 'SHEET';

    public function label(): string
    {
        return match ($this) {
            self::CHUTE => 'Manga',
            self::MANUAL => 'Manual',
            self::SHEET => 'Planilla ING-03',
        };
    }
}
