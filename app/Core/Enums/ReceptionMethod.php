<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * How a caravan was received: read at the chute, declared received by hand from the order, or
 * marked on an ING-03 receipt sheet and scanned.
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

    /**
     * Whether it is a declaration of what arrived — and so may also declare what never will. The
     * chute only reads the caravans that went through it.
     */
    public function declaresMissing(): bool
    {
        return $this !== self::CHUTE;
    }
}
