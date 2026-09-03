<?php

declare(strict_types=1);

namespace App\Core\Enums;

enum LibidoLevel: string
{
    case BAJA = 'BAJA';
    case MEDIA = 'MEDIA';
    case ALTA = 'ALTA';
    case MUY_ALTA = 'MUY_ALTA';
}
