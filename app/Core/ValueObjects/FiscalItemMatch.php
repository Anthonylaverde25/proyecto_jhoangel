<?php

declare(strict_types=1);

namespace App\Core\ValueObjects;

/**
 * The matcher's answer for one line (Delivery G, G12): the item and how sure, as a level.
 */
final readonly class FiscalItemMatch
{
    public const HIGH = 'HIGH';
    public const MEDIUM = 'MEDIUM';
    public const NONE = 'NONE';

    public function __construct(
        public int $position,
        public ?int $itemId,
        public string $level,
        public ?string $reason,
    ) {
    }
}
