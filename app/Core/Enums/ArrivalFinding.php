<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * What the chute saw on an animal as it came off the truck, most likely from the trip: an injured
 * eye, a damaged ear, or a limb problem (APLOMO on paper: lameness or a knock on the legs). A box
 * marked on its reception line; an unmarked one is "not seen", never "sound".
 */
enum ArrivalFinding: string
{
    case EYE = 'EYE';
    case EAR = 'EAR';
    case LIMB = 'LIMB';

    public function label(): string
    {
        return match ($this) {
            self::EYE => 'Ojo',
            self::EAR => 'Oreja',
            self::LIMB => 'Aplomo',
        };
    }
}
