<?php

declare(strict_types=1);

namespace App\Core\Enums;

enum GestationStage: string
{
    case HEAD = 'head';
    case BODY = 'body';
    case TAIL = 'tail';

    /**
     * Determine the gestation stage from the approximate months.
     */
    public static function fromMonths(float $months): self
    {
        if ($months <= 1.0) {
            return self::TAIL;
        }

        if ($months <= 2.0) {
            return self::BODY;
        }

        return self::HEAD;
    }

    /**
     * Get the default approximate months for the stage.
     */
    public function toDefaultMonths(): float
    {
        return match ($this) {
            self::TAIL => 1.0,
            self::BODY => 2.0,
            self::HEAD => 3.0,
        };
    }
}
