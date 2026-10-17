<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * Where an animal stands for breeding, combining two independent facts:
 *   1. Whether its category admits breeding (animal_categories.is_reproductive), declared by
 *      the owner of the catalog: a calf, a steer or a castrated yearling never does.
 *   2. The owner's decision (caravans.is_breeding_eligible), which only matters once the
 *      category admits breeding.
 *
 * The category is never written over the owner's flag: a calf is not "excluded", breeding
 * simply does not apply to it yet, and it applies on its own once it is recategorized at
 * weaning. A caravan without category is treated as reproductive, as it always was.
 */
enum ReproductiveStatus: string
{
    case NOT_APPLICABLE = 'NOT_APPLICABLE';
    case ELIGIBLE = 'ELIGIBLE';
    case EXCLUDED = 'EXCLUDED';

    public static function of(?bool $isReproductiveCategory, bool $isBreedingEligible): self
    {
        if ($isReproductiveCategory === false) {
            return self::NOT_APPLICABLE;
        }

        return $isBreedingEligible ? self::ELIGIBLE : self::EXCLUDED;
    }

    public function label(): string
    {
        return match ($this) {
            self::NOT_APPLICABLE => 'No aplica',
            self::ELIGIBLE => 'Apta',
            self::EXCLUDED => 'Excluida',
        };
    }
}
