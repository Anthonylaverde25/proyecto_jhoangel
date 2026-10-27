<?php

declare(strict_types=1);

namespace App\Core\ValueObjects;

/**
 * A provider a task concerns, as the task shows it.
 */
final class TaskProviderRef
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
    ) {
    }
}
