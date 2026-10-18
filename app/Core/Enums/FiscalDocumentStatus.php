<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * A registered provider document never changes; voiding it is the only later change (DS20).
 */
enum FiscalDocumentStatus: string
{
    case REGISTERED = 'REGISTERED';
    case VOIDED = 'VOIDED';
}
