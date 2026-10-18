<?php

declare(strict_types=1);

namespace App\Core\Enums;

enum StockIncidentStatus: string
{
    case OPEN = 'OPEN';
    case RESOLVED = 'RESOLVED';
}
