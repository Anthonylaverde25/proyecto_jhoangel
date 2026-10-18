<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * Physical dimension of a unit of measure. Units only convert automatically within one dimension.
 */
enum UnitDimension: string
{
    case MASS = 'MASS';
    case VOLUME = 'VOLUME';
    case COUNT = 'COUNT';
    case TIME = 'TIME';
    case AREA = 'AREA';
    case LENGTH = 'LENGTH';

    public function label(): string
    {
        return match ($this) {
            self::MASS => 'Masa',
            self::VOLUME => 'Volumen',
            self::COUNT => 'Conteo',
            self::TIME => 'Tiempo',
            self::AREA => 'Área',
            self::LENGTH => 'Longitud',
        };
    }
}
