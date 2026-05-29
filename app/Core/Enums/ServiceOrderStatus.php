<?php

declare(strict_types=1);

namespace App\Core\Enums;

enum ServiceOrderStatus: string
{
    case DRAFT = 'DRAFT';
    case APPROVED = 'APPROVED';
    case SUCCESS = 'SUCCESS';
    case REJECTED = 'REJECTED';
    case CANCELLED = 'CANCELLED';
}
