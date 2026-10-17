<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * Current verdict of a female for one service order.
 *
 * UNCHECKED blocks the closing of the order. NOT_DIAGNOSED is declared by the producer, with a
 * reason; EXITED is set by the system when she died or was sold before any verdict. Neither of
 * them counts for the pregnancy rate.
 */
enum FemaleReproductiveVerdict: string
{
    case UNCHECKED = 'UNCHECKED';
    case PREGNANT = 'PREGNANT';
    case EMPTY = 'EMPTY';
    case NOT_DIAGNOSED = 'NOT_DIAGNOSED';
    case EXITED = 'EXITED';

    public function label(): string
    {
        return match ($this) {
            self::UNCHECKED => 'Sin tactar',
            self::PREGNANT => 'Preñada',
            self::EMPTY => 'Vacía',
            self::NOT_DIAGNOSED => 'Sin diagnóstico',
            self::EXITED => 'Salió (muerte / venta)',
        };
    }

    /**
     * A verdict a diagnosis round can give (the other two are not diagnoses).
     */
    public function isDiagnosis(): bool
    {
        return in_array($this, [self::PREGNANT, self::EMPTY, self::NOT_DIAGNOSED], true);
    }
}
