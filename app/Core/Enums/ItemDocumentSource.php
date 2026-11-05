<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * Who originated a stock operation, when it is declared. Set by the route, never read from a request.
 */
enum ItemDocumentSource: string
{
    case AI = 'AI';
}
