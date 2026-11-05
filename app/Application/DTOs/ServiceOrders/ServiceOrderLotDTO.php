<?php

declare(strict_types=1);

namespace App\Application\DTOs\ServiceOrders;

/** One batch of the "Nueva orden" sheet: each batch becomes its own service order. */
final readonly class ServiceOrderLotDTO
{
    /**
     * @param int[] $maleCaravanIds
     * @param int[]|null $selectedFemaleCaravanIds null: every empty female of the batch (natural service)
     * @param array<int, int> $plannedSemen female_caravan_id => semen item id (AI only)
     */
    public function __construct(
        public int $batchId,
        public array $maleCaravanIds = [],
        public ?array $selectedFemaleCaravanIds = null,
        public bool $isControlledService = false,
        public string $serviceType = 'multi',
        public array $plannedSemen = []
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $plannedSemen = [];
        foreach ((array) ($data['planned_semen'] ?? []) as $row) {
            if (isset($row['female_caravan_id'], $row['item_id'])) {
                $plannedSemen[(int) $row['female_caravan_id']] = (int) $row['item_id'];
            }
        }

        return new self(
            batchId: (int) $data['batch_id'],
            maleCaravanIds: array_values(array_map('intval', (array) ($data['male_caravan_ids'] ?? []))),
            selectedFemaleCaravanIds: isset($data['selected_female_caravan_ids'])
                ? array_values(array_map('intval', (array) $data['selected_female_caravan_ids']))
                : null,
            isControlledService: (bool) ($data['is_controlled_service'] ?? false),
            serviceType: (string) ($data['service_type'] ?? 'multi'),
            plannedSemen: $plannedSemen
        );
    }
}
