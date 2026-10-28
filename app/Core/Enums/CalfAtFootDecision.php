<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * What happens to the calf at foot of a cow that leaves (P1): it leaves with her (the default the
 * grid marks), or it stays and is weaned that day where it stands.
 */
enum CalfAtFootDecision: string
{
    case WITH_MOTHER = 'WITH_MOTHER';
    case STAYS = 'STAYS';

    public function label(): string
    {
        return match ($this) {
            self::WITH_MOTHER => 'Sale con ella',
            self::STAYS => 'Se queda',
        };
    }
}
