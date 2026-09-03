<?php

declare(strict_types=1);

namespace App\Core\Enums;

enum DiagnosisStatus: string
{
    case CONFIRMED_POSITIVE = 'CONFIRMED_POSITIVE';
    case IN_TREATMENT = 'IN_TREATMENT';
    case RESOLVED = 'RESOLVED';
    case SUSPECTED = 'SUSPECTED';

    public function isActive(): bool
    {
        return $this === self::CONFIRMED_POSITIVE || $this === self::IN_TREATMENT;
    }
}
