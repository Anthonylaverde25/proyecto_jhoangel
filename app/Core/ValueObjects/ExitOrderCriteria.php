<?php

declare(strict_types=1);

namespace App\Core\ValueObjects;

use App\Core\Enums\ExitOrderKind;
use App\Core\Enums\ExitOrderStatus;

/** Filters of the exit orders tray (egreso plan v5, 05 §3). */
final readonly class ExitOrderCriteria
{
    public function __construct(
        public int $companyId,
        public ?ExitOrderStatus $status = null,
        public ?ExitOrderKind $kind = null,
        public bool $withoutDte = false,
        public ?int $buyerPartnerId = null,
        public ?string $from = null,
        public ?string $to = null,
        public ?int $activityId = null,
    ) {
    }
}
