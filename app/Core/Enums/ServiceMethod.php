<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * How the females of a service order are served (semen S-B): by bulls sharing the paddock, or by
 * straws of semen. Repaso bulls joining an AI order do not change its method.
 */
enum ServiceMethod: string
{
    case NATURAL = 'NATURAL';
    case AI = 'AI';

    public function label(): string
    {
        return match ($this) {
            self::NATURAL => 'Monta natural',
            self::AI => 'Inseminación artificial',
        };
    }

    /** AI, like a controlled service, picks its females one by one and leaves out excluded ones (IA2). */
    public function picksFemalesIndividually(): bool
    {
        return $this === self::AI;
    }

    /** A natural service cannot start without bulls; an AI one needs none (IA3). */
    public function needsBulls(): bool
    {
        return $this === self::NATURAL;
    }
}
