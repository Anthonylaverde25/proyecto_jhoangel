<?php

declare(strict_types=1);

namespace App\Core\Enums;

enum GestationResult: string
{
    case SUCCESSFUL = 'successful'; // Parto exitoso
    case FAILED = 'failed';         // Aborto / Pérdida
}
