<?php

declare(strict_types=1);

namespace App\Application\DTOs\ServiceOrders;

use App\Core\Enums\ServiceMethod;

final class StartBatchServiceOrderDTO
{
    /**
     * @param int[] $maleCaravanIds
     * @param int[]|null $selectedFemaleCaravanIds
     * @param array<int, array{female_caravan_id: int, assigned_male_caravan_id: int}> $femaleSireAssignments
     * @param array<int, int> $plannedSemen female_caravan_id => semen item id (AI only, IA4)
     */
    public function __construct(
        public readonly int $batchId,
        public readonly int $companyId,
        public readonly int $userId,
        public readonly array $maleCaravanIds,
        public readonly ?array $selectedFemaleCaravanIds = null,
        public readonly float $targetBullRatio = 3.0,
        public readonly string $plannedStartDate = '',
        public readonly ?string $plannedEndDate = null,
        public readonly string $serviceType = 'multi',
        public readonly bool $isControlledService = false,
        public readonly array $femaleSireAssignments = [],
        public readonly ?string $observations = null,
        /** The order this one continues: the bulls stayed with the empty females of a closed one. */
        public readonly ?int $parentServiceOrderId = null,
        public readonly ServiceMethod $serviceMethod = ServiceMethod::NATURAL,
        public readonly array $plannedSemen = []
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            batchId: (int) $data['batch_id'],
            companyId: (int) $data['company_id'],
            userId: (int) $data['user_id'],
            maleCaravanIds: array_map('intval', (array) ($data['male_caravan_ids'] ?? [])),
            selectedFemaleCaravanIds: isset($data['selected_female_caravan_ids']) && is_array($data['selected_female_caravan_ids'])
                ? array_map('intval', $data['selected_female_caravan_ids'])
                : null,
            targetBullRatio: isset($data['target_bull_ratio']) ? (float) $data['target_bull_ratio'] : 3.0,
            plannedStartDate: (string) ($data['planned_start_date'] ?? now()->format('Y-m-d')),
            plannedEndDate: isset($data['planned_end_date']) && trim((string)$data['planned_end_date']) !== ''
                ? (string) $data['planned_end_date']
                : null,
            serviceType: (string) ($data['service_type'] ?? 'multi'),
            isControlledService: (bool) ($data['is_controlled_service'] ?? false),
            femaleSireAssignments: (array) ($data['female_sire_assignments'] ?? []),
            observations: isset($data['observations']) ? (string) $data['observations'] : null,
            serviceMethod: ServiceMethod::tryFrom((string) ($data['service_method'] ?? '')) ?? ServiceMethod::NATURAL,
            plannedSemen: self::plannedSemen((array) ($data['planned_semen'] ?? []))
        );
    }

    /**
     * @param array<int, mixed> $rows
     * @return array<int, int>
     */
    private static function plannedSemen(array $rows): array
    {
        $planned = [];
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['female_caravan_id'], $row['item_id']) && $row['item_id'] !== null) {
                $planned[(int) $row['female_caravan_id']] = (int) $row['item_id'];
            }
        }

        return $planned;
    }

    /** Females picked one by one: a controlled service or an AI one (IA2). */
    public function picksFemalesIndividually(): bool
    {
        return $this->isControlledService || $this->serviceMethod->picksFemalesIndividually();
    }
}
