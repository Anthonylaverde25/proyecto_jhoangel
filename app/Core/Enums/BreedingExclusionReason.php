<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * Why the owner left an animal out of breeding. These are management reasons only: the
 * veterinary verdict lives in bull_health_evaluations and never becomes one of these codes.
 */
enum BreedingExclusionReason: string
{
    case CUT_DENTITION = 'CUT_DENTITION';
    case LOW_BODY_CONDITION = 'LOW_BODY_CONDITION';
    case REPRODUCTIVE_DEFECT = 'REPRODUCTIVE_DEFECT';
    case IMMATURE = 'IMMATURE';
    case LOCOMOTOR_DEFECT = 'LOCOMOTOR_DEFECT';
    case SENILE = 'SENILE';
    case RESERVED = 'RESERVED';
    case RESTING = 'RESTING';
    case SCHEDULED_FOR_SALE = 'SCHEDULED_FOR_SALE';
    case OTHER = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::CUT_DENTITION => 'Vaca CUT / desgaste dental',
            self::LOW_BODY_CONDITION => 'Baja condición corporal',
            self::REPRODUCTIVE_DEFECT => 'Problema reproductivo',
            self::IMMATURE => 'Sin desarrollo / se retiene sin servicio',
            self::LOCOMOTOR_DEFECT => 'Aplomos / renguera',
            self::SENILE => 'Toro viejo',
            self::RESERVED => 'Reservado (cabaña / IA)',
            self::RESTING => 'Descanso',
            self::SCHEDULED_FOR_SALE => 'Destino venta / engorde',
            self::OTHER => 'Otro motivo',
        };
    }

    public function appliesTo(AnimalSex $sex): bool
    {
        return match ($this) {
            self::CUT_DENTITION,
            self::LOW_BODY_CONDITION,
            self::REPRODUCTIVE_DEFECT,
            self::IMMATURE => $sex === AnimalSex::FEMALE,
            self::LOCOMOTOR_DEFECT,
            self::SENILE,
            self::RESERVED,
            self::RESTING => $sex === AnimalSex::MALE,
            self::SCHEDULED_FOR_SALE,
            self::OTHER => true,
        };
    }

    public function requiresNote(): bool
    {
        return $this === self::OTHER;
    }
}
