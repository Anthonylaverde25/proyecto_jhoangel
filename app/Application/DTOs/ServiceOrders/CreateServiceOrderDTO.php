<?php

declare(strict_types=1);

namespace App\Application\DTOs\ServiceOrders;

final readonly class CreateServiceOrderDTO
{
    /**
     * @param int[] $maleCaravanIds
     * @param int[] $femaleCaravanIds
     */
    public function __construct(
        public int $companyId,
        public int $batchId,
        public string $code,
        public string $plannedStartDate,
        public int $requestedByUserId,
        public ?string $observations = null,
        public array $maleCaravanIds = [],
        public array $femaleCaravanIds = []
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (int) $data['company_id'],
            (int) $data['batch_id'],
            (string) $data['code'],
            (string) $data['planned_start_date'],
            (int) $data['requested_by_user_id'],
            isset($data['observations']) ? (string) $data['observations'] : null,
            (array) ($data['male_caravan_ids'] ?? []),
            (array) ($data['female_caravan_ids'] ?? [])
        );
    }
}
