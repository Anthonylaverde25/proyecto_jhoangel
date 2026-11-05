<?php

declare(strict_types=1);

namespace App\Application\DTOs\ServiceOrders;

use App\Core\Enums\ServiceMethod;

/** The "Registrar orden" sheet: services that already ended in the field, one order per batch. */
final readonly class RegisterServiceOrdersDTO
{
    /** @param list<array{batch_id: int, female_caravan_ids: int[], bulls: list<RegisteredBullStayDTO>}> $lots */
    public function __construct(
        public int $companyId,
        public int $userId,
        public ServiceMethod $serviceMethod,
        public string $startDate,
        public string $endDate,
        public ?string $observations,
        public array $lots
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, int $companyId, int $userId): self
    {
        $lots = [];
        foreach ((array) $data['lots'] as $lot) {
            $lots[] = [
                'batch_id' => (int) $lot['batch_id'],
                'female_caravan_ids' => array_values(array_unique(array_map('intval', (array) ($lot['female_caravan_ids'] ?? [])))),
                'bulls' => array_values(array_map(RegisteredBullStayDTO::fromArray(...), (array) ($lot['bulls'] ?? []))),
            ];
        }

        return new self(
            companyId: $companyId,
            userId: $userId,
            serviceMethod: ServiceMethod::from((string) $data['service_method']),
            startDate: substr((string) $data['start_date'], 0, 10),
            endDate: substr((string) $data['end_date'], 0, 10),
            observations: $data['observations'] ?? null,
            lots: $lots
        );
    }
}
