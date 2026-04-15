<?php

declare(strict_types=1);

namespace App\Core\Enums;

enum WorkType: string
{
    case ENTRY = 'entry';
    case UPDATE = 'update';
    case EXIT = 'exit';
}
