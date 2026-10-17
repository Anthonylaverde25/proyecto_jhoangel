<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * What happens with the non-pregnant females of a closed order that stay with the bulls.
 *
 * Left alone they would go on being exposed with no order recording it: a pregnancy there would
 * belong to no service, no bull and no rate.
 */
enum ServiceClosureRemainingFemalesAction: string
{
    /** They leave the service batch now. */
    case MOVE_OUT = 'MOVE_OUT';
    /** A continuation order keeps them in service with the same bulls. */
    case CONTINUE_SERVICE = 'CONTINUE_SERVICE';
}
