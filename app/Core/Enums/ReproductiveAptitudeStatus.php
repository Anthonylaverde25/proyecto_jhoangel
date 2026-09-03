<?php

declare(strict_types=1);

namespace App\Core\Enums;

enum ReproductiveAptitudeStatus: string
{
    case APT = 'APT';
    case UNFIT = 'UNFIT';
    case IN_TREATMENT = 'IN_TREATMENT';
    case PENDING_EVALUATION = 'PENDING_EVALUATION';

    public function isApt(): bool
    {
        return $this === self::APT;
    }

    public function isBlocked(): bool
    {
        return $this !== self::APT;
    }
}
