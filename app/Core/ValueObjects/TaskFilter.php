<?php

declare(strict_types=1);

namespace App\Core\ValueObjects;

use App\Core\Enums\TaskUrgency;

final class TaskFilter
{
    public const DEFAULT_PER_PAGE = 50;
    public const MAX_PER_PAGE = 100;

    public function __construct(
        public readonly int $companyId,
        public readonly ?TaskUrgency $urgency = null,
        public readonly ?int $providerId = null,
        public readonly ?string $search = null,
        public readonly int $page = 1,
        public readonly int $perPage = self::DEFAULT_PER_PAGE,
    ) {
    }
}
