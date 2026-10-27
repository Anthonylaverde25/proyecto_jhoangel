<?php

declare(strict_types=1);

namespace App\Application\DTOs\Task;

use App\Core\Enums\TaskUrgency;

/**
 * A partial edit: null keeps the current value. An empty provider list removes every provider.
 */
final class UpdateTaskDTO
{
    /**
     * @param list<int>|null $providerIds
     */
    public function __construct(
        public readonly int $taskId,
        public readonly int $companyId,
        public readonly ?string $description = null,
        public readonly ?TaskUrgency $urgency = null,
        public readonly ?array $providerIds = null,
    ) {
    }
}
