<?php

declare(strict_types=1);

namespace App\Application\DTOs\ServiceOrders;

use App\Core\Enums\ServiceMethod;

/** The "Nueva orden" sheet: what is common to its batches, and the batches. */
final readonly class IssueServiceOrdersDTO
{
    /** @param list<ServiceOrderLotDTO> $lots */
    public function __construct(
        public int $companyId,
        public int $userId,
        public ServiceMethod $serviceMethod,
        public string $startDate,
        public ?string $endDate,
        public ?string $observations,
        public array $lots
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, int $companyId, int $userId): self
    {
        return new self(
            companyId: $companyId,
            userId: $userId,
            serviceMethod: ServiceMethod::from((string) $data['service_method']),
            startDate: substr((string) $data['start_date'], 0, 10),
            endDate: isset($data['end_date']) ? substr((string) $data['end_date'], 0, 10) : null,
            observations: $data['observations'] ?? null,
            lots: array_values(array_map(ServiceOrderLotDTO::fromArray(...), (array) $data['lots']))
        );
    }
}
