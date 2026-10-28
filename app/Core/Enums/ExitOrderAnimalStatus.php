<?php

declare(strict_types=1);

namespace App\Core\Enums;

/** One animal of an exit order: waiting to leave, gone, or taken off the roll with its reason. */
enum ExitOrderAnimalStatus: string
{
    case PENDING = 'PENDING';
    case EXITED = 'EXITED';
    case SKIPPED = 'SKIPPED';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pendiente',
            self::EXITED => 'Salió',
            self::SKIPPED => 'No salió',
        };
    }
}
