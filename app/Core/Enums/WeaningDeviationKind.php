<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * What a weaning did differently from its order. Recorded after the fact, never blocking.
 */
enum WeaningDeviationKind: string
{
    case DESTINATION = 'DESTINATION';
    case WEANING_TYPE = 'WEANING_TYPE';
    case CATEGORY = 'CATEGORY';
    case LATE = 'LATE';
    case NOT_WEANED = 'NOT_WEANED';

    public function label(): string
    {
        return match ($this) {
            self::DESTINATION => 'Destino',
            self::WEANING_TYPE => 'Tipo de destete',
            self::CATEGORY => 'Categoría',
            self::LATE => 'Destete tardío',
            self::NOT_WEANED => 'No destetadas',
        };
    }
}
