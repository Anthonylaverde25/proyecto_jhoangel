<?php

declare(strict_types=1);

namespace App\Core\Enums;

enum ServiceOrderStatus: string
{
    case DRAFT = 'DRAFT';
    case PENDING_REVIEW = 'PENDING_REVIEW';
    case PENDING_APPROVAL = 'PENDING_APPROVAL';
    case APPROVED = 'APPROVED';
    case IN_PROGRESS = 'IN_PROGRESS';
    case COMPLETED = 'COMPLETED';
    case REJECTED = 'REJECTED';
    case CANCELLED = 'CANCELLED';
}
