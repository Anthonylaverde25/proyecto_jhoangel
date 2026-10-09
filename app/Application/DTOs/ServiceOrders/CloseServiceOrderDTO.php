<?php

declare(strict_types=1);

namespace App\Application\DTOs\ServiceOrders;

final readonly class CloseServiceOrderDTO
{
    /**
     * @param array<int, int> $bullDestinations Map of male_caravan_id => destination_batch_id
     */
    public function __construct(
        public int $serviceOrderId,
        public int $companyId,
        public int $userId,
        public string $withdrawalDate,
        public ?int $defaultDestinationBatchId = null,
        public array $bullDestinations = [],
        public ?string $observations = null
    ) {
    }
}
