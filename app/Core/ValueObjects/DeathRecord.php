<?php

declare(strict_types=1);

namespace App\Core\ValueObjects;

/**
 * The death of an animal as it was declared: the day and the cause of the catalog.
 */
final readonly class DeathRecord
{
    public function __construct(
        public string $date,
        public int $causeId,
        public ?string $causeName = null,
        public ?string $observations = null
    ) {
    }
}
