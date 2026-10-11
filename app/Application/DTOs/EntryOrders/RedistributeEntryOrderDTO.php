<?php

declare(strict_types=1);

namespace App\Application\DTOs\EntryOrders;

use App\Core\ValueObjects\EntryDestinationPlan;

/**
 * "Editar distribución": where the animals of an order go from now on, by category line and sex,
 * and the name of the transit batch when something is left without a destination and the order
 * has none yet.
 */
final readonly class RedistributeEntryOrderDTO
{
    public function __construct(
        public EntryDestinationPlan $destinations,
        public ?string $transitBatchName
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $name = trim((string) ($data['transit_batch_name'] ?? ''));

        return new self(
            destinations: StoreEntryOrderDTO::destinationsOf($data['destinations'] ?? []),
            transitBatchName: $name !== '' ? $name : null
        );
    }
}
