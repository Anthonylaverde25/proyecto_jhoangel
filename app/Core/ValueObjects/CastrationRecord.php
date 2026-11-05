<?php

declare(strict_types=1);

namespace App\Core\ValueObjects;

use App\Core\Enums\CastrationMethod;

/**
 * An active castration of an animal: the day, method, and notes declared when performed.
 */
final readonly class CastrationRecord
{
    public function __construct(
        public int $id,
        public string $castratedOn,
        public ?CastrationMethod $method = null,
        public ?string $notes = null,
        public ?string $fromCategoryCode = null
    ) {
    }
}

