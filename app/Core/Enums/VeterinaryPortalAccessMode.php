<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * The veterinary portal is reachable two ways: by an in-system user holding the
 * `veterinarian` role on `company_user`, or by an external professional carrying a
 * temporary access token handed out by the producer.
 */
enum VeterinaryPortalAccessMode: string
{
    case INTERNAL_USER = 'INTERNAL_USER';
    case TEMPORARY_TOKEN = 'TEMPORARY_TOKEN';
}
