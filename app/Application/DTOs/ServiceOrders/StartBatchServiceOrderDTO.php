<?php

declare(strict_types=1);

namespace App\Application\DTOs\ServiceOrders;

final class StartBatchServiceOrderDTO
{
    /**
     * @param int[] $maleCaravanIds
     * @param int[]|null $selectedFemaleCaravanIds
     * @param array<int, array{female_caravan_id: int, assigned_male_caravan_id: int}> $femaleSireAssignments
     */
    public function __construct(
        public readonly int $originBatchId,
        public readonly int $companyId,
        public readonly int $userId,
        public readonly ?string $serviceBatchName,
        public readonly array $maleCaravanIds,
        public readonly ?array $selectedFemaleCaravanIds = null,
        public readonly float $targetBullRatio = 3.0,
        public readonly string $plannedStartDate = '',
        public readonly ?string $plannedEndDate = null,
        public readonly string $serviceType = 'multi',
        public readonly bool $isControlledService = false,
        public readonly array $femaleSireAssignments = [],
        public readonly ?string $observations = null
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            originBatchId: (int) ($data['origin_batch_id'] ?? $data['batch_id']),
            companyId: (int) $data['company_id'],
            userId: (int) $data['user_id'],
            serviceBatchName: isset($data['service_batch_name']) && trim((string)$data['service_batch_name']) !== ''
                ? trim((string)$data['service_batch_name'])
                : null,
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
            observations: isset($data['observations']) ? (string) $data['observations'] : null
        );
    }
}
