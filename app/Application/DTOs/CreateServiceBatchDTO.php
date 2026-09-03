<?php

declare(strict_types=1);

namespace App\Application\DTOs;

final readonly class CreateServiceBatchDTO
{
    /**
     * @param int[] $femaleCaravanIds
     * @param int[] $maleCaravanIds
     */
    public function __construct(
        public string $name,
        public int $femaleCategoryId,
        public int $maleCategoryId,
        public ?int $femaleSubcategoryId = null,
        public array $femaleCaravanIds = [],
        public array $maleCaravanIds = [],
        public ?int $farmId = null,
        public ?float $targetBullRatio = 3.00,
        public ?string $plannedStartDate = null,
        public ?string $plannedEndDate = null,
        public ?string $notes = null,
        public ?string $observaciones = null,
        public bool $autoCreateServiceOrder = true
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['name'] ?? ''),
            (int) ($data['female_category_id'] ?? 0),
            (int) ($data['male_category_id'] ?? 0),
            isset($data['female_subcategory_id']) && $data['female_subcategory_id'] !== null ? (int) $data['female_subcategory_id'] : null,
            isset($data['female_caravan_ids']) && is_array($data['female_caravan_ids'])
                ? array_map('intval', $data['female_caravan_ids'])
                : [],
            isset($data['male_caravan_ids']) && is_array($data['male_caravan_ids'])
                ? array_map('intval', $data['male_caravan_ids'])
                : [],
            isset($data['farm_id']) && $data['farm_id'] !== null ? (int) $data['farm_id'] : null,
            isset($data['target_bull_ratio']) ? (float) $data['target_bull_ratio'] : 3.00,
            isset($data['planned_start_date']) ? (string) $data['planned_start_date'] : null,
            isset($data['planned_end_date']) ? (string) $data['planned_end_date'] : null,
            isset($data['notes']) ? (string) $data['notes'] : null,
            isset($data['observaciones']) ? (string) $data['observaciones'] : null,
            (bool) ($data['auto_create_service_order'] ?? true)
        );
    }
}
