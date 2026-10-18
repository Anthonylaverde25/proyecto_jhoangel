<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * Whether a tax is charged on each line (VAT, fuel tax, internal taxes) or on the whole provider
 * document (perceptions). Tells which table it is stored in (DS16).
 */
enum TaxScope: string
{
    case LINE = 'LINE';
    case DOCUMENT = 'DOCUMENT';
}
