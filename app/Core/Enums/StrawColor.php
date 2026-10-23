<?php

declare(strict_types=1);

namespace App\Core\Enums;

/** Colour of a straw or of its cap, as printed on the centre's catalogue. */
enum StrawColor: string
{
    case TRANSPARENT = 'TRANSPARENT';
    case WHITE = 'WHITE';
    case YELLOW = 'YELLOW';
    case ORANGE = 'ORANGE';
    case RED = 'RED';
    case PINK = 'PINK';
    case PURPLE = 'PURPLE';
    case BLUE = 'BLUE';
    case GREEN = 'GREEN';
    case GRAY = 'GRAY';
    case BROWN = 'BROWN';
    case BLACK = 'BLACK';

    public function label(): string
    {
        return match ($this) {
            self::TRANSPARENT => 'Transparente',
            self::WHITE => 'Blanco',
            self::YELLOW => 'Amarillo',
            self::ORANGE => 'Naranja',
            self::RED => 'Rojo',
            self::PINK => 'Rosa',
            self::PURPLE => 'Violeta',
            self::BLUE => 'Azul',
            self::GREEN => 'Verde',
            self::GRAY => 'Gris',
            self::BROWN => 'Marrón',
            self::BLACK => 'Negro',
        };
    }
}
