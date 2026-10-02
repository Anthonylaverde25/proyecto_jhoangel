<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * How the batch of an entry order got its name: composed by the server from the auction number
 * and the order number, or written by the user.
 */
enum BatchNameMode: string
{
    case AUTO = 'AUTO';
    case CUSTOM = 'CUSTOM';
}
