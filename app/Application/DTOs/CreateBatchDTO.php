<?php

declare(strict_types=1);

namespace App\Application\DTOs;

final readonly class CreateBatchDTO
{
    public function __construct(
        public string $name,
        public ?int $farmId = null,
        public ?string $observaciones = null,
        public ?int $activityId = null,
        public ?float $weight = null,
        public ?int $batchTypeId = null,
        public bool $knowsToEat = false,
        public ?int $ageInMonths = null,
        public ?float $minWeight = null,
        public ?float $maxWeight = null
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['name'] ?? ''),
            isset($data['farm_id']) ? (int) $data['farm_id'] : null,
            isset($data['observaciones']) ? (string) $data['observaciones'] : null,
            isset($data['activity_id']) ? (int) $data['activity_id'] : null,
            isset($data['weight']) ? (float) $data['weight'] : null,
            isset($data['batch_type_id']) ? (int) $data['batch_type_id'] : null,
            (bool) ($data['knows_to_eat'] ?? false),
            isset($data['age_in_months']) ? (int) $data['age_in_months'] : null,
            isset($data['min_weight']) ? (float) $data['min_weight'] : null,
            isset($data['max_weight']) ? (float) $data['max_weight'] : null
        );
    }
}
