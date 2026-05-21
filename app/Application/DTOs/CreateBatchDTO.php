<?php

declare(strict_types=1);

namespace App\Application\DTOs;

final readonly class CreateBatchDTO
{
    public function __construct(
        public string $name,
        public int $farmId,
        public ?string $observaciones = null,
        public ?int $activityId = null,
        public ?float $weight = null,
        public ?int $batchTypeId = null
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['name'] ?? ''),
            (int) ($data['farm_id'] ?? 0),
            isset($data['observaciones']) ? (string) $data['observaciones'] : null,
            isset($data['activity_id']) ? (int) $data['activity_id'] : null,
            isset($data['weight']) ? (float) $data['weight'] : null,
            isset($data['batch_type_id']) ? (int) $data['batch_type_id'] : null
        );
    }
}
