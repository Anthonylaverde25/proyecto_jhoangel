<?php

declare(strict_types=1);

namespace App\Application\DTOs;

final class BulkRecordCaravanWeightDTO
{
    /**
     * @param RecordCaravanWeightDTO[] $weights
     */
    public function __construct(
        public array $weights
    ) {
    }
}
