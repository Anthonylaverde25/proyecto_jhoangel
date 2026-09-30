<?php

declare(strict_types=1);

namespace App\Core\Enums;

enum CaravanLookupStatus: string
{
    case NOT_FOUND = 'not_found';
    case OWN_COMPANY = 'own_company';
    case OTHER_COMPANY = 'other_company';
}
