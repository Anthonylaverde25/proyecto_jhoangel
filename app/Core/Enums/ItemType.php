<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * What an item is (D11). Whether it is counted in a warehouse is a separate flag, track_stock.
 */
enum ItemType: string
{
    case ARTICLE = 'ARTICLE';
    case SERVICE = 'SERVICE';

    /** Prefix of the codes the system generates when the operator leaves the code empty. */
    public function codePrefix(): string
    {
        return match ($this) {
            self::ARTICLE => 'ART',
            self::SERVICE => 'SRV',
        };
    }
}
