<?php

declare(strict_types=1);

namespace App\Application\DTOs\Task;

use App\Core\Enums\TaskUrgency;

final class CreateTaskDTO
{
    /**
     * @param list<int> $providerIds
     */
    public function __construct(
        public readonly int $companyId,
        public readonly string $description,
        public readonly TaskUrgency $urgency = TaskUrgency::NOT_URGENT,
        public readonly array $providerIds = [],
    ) {
    }
}
