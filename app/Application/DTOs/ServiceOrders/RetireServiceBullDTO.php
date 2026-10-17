<?php

declare(strict_types=1);

namespace App\Application\DTOs\ServiceOrders;

use App\Core\Enums\BullReplacementReason;

final readonly class RetireServiceBullDTO
{
    public function __construct(
        public int $serviceOrderId,
        public int $companyId,
        public int $userId,
        public int $maleCaravanId,
        public string $retirementDate,
        public BullReplacementReason $reason,
        public ?int $destinationBatchId = null,
        public ?string $notes = null
    ) {
    }
}
