<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * How urgent a task is, in three levels. A task is not urgent unless someone says otherwise.
 */
enum TaskUrgency: string
{
    case NOT_URGENT = 'not_urgent';
    case URGENT = 'urgent';
    case VERY_URGENT = 'very_urgent';

    public static function default(): self
    {
        return self::NOT_URGENT;
    }

    /** 1 (not urgent) to 3 (very urgent): listings show the most urgent first. */
    public function rank(): int
    {
        return match ($this) {
            self::NOT_URGENT => 1,
            self::URGENT => 2,
            self::VERY_URGENT => 3,
        };
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return array_map(static fn (self $urgency): string => $urgency->value, self::cases());
    }
}
