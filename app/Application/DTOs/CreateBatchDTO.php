<?php

declare(strict_types=1);

namespace App\Application\DTOs;

/**
 * `isConfined` is the only tri-state field here: true = penned, false = pasture and
 * null = nobody declared it. The paths where a person fills a form demand the answer
 * (see ValidatesBatchClassification); the paths that create a batch out of a scanned
 * sheet that never asked leave it null rather than inventing a "pasture".
 */
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
        public ?bool $isConfined = null,
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
            isset($data['is_confined']) ? (bool) $data['is_confined'] : null,
            isset($data['age_in_months']) ? (int) $data['age_in_months'] : null,
            isset($data['min_weight']) ? (float) $data['min_weight'] : null,
            isset($data['max_weight']) ? (float) $data['max_weight'] : null
        );
    }
}
