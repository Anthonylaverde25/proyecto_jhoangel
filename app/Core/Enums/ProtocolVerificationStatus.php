<?php

declare(strict_types=1);

namespace App\Core\Enums;

enum ProtocolVerificationStatus: string
{
    case UNVERIFIED = 'UNVERIFIED';
    case VERIFIED = 'VERIFIED';
}
