<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * Why a female stopped being exposed to the bulls of a service order.
 */
enum ServiceExposureExitReason: string
{
    case TRANSFERRED_OUT = 'TRANSFERRED_OUT';
    case BULLS_WITHDRAWN = 'BULLS_WITHDRAWN';
    case SERVICE_CLOSED = 'SERVICE_CLOSED';
    case DEATH = 'DEATH';
    case SALE = 'SALE';

    /**
     * Maps the type of a caravan movement that took the female out of the serviced batch.
     */
    public static function fromMovementType(string $movementType): self
    {
        return match (strtoupper($movementType)) {
            'DEATH' => self::DEATH,
            'SALE' => self::SALE,
            default => self::TRANSFERRED_OUT,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::TRANSFERRED_OUT => 'Salió del lote',
            self::BULLS_WITHDRAWN => 'Retiro de torada',
            self::SERVICE_CLOSED => 'Servicio cerrado',
            self::DEATH => 'Muerte',
            self::SALE => 'Venta',
        };
    }
}
