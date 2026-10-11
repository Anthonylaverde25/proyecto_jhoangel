<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * Which animals of a category line a destination rule takes: all of them, or only one sex — for a
 * line that admits both in a troop of both sexes ("calves, males → batch A").
 */
enum DestinationSexScope: string
{
    case ALL = 'ALL';
    case MALE = 'M';
    case FEMALE = 'H';

    public static function of(AnimalSex $sex): self
    {
        return $sex === AnimalSex::MALE ? self::MALE : self::FEMALE;
    }

    public function matches(AnimalSex $sex): bool
    {
        return $this === self::ALL || $this === self::of($sex);
    }

    public function label(): string
    {
        return match ($this) {
            self::ALL => 'todas',
            self::MALE => 'machos',
            self::FEMALE => 'hembras',
        };
    }
}
