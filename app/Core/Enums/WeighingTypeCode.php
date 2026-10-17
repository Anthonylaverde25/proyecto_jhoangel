<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * Why an animal was put on the scale. The zootechnical purpose decides how the weight is read:
 * a weight taken off a truck is not growth produced on the farm, and mixing it into the daily
 * gain curve draws jumps nobody caused.
 *
 * This enum is the single source of the rules. The weighing_types table is the per-tenant
 * catalog (labels, order, active flag) and is seeded from here.
 */
enum WeighingTypeCode: string
{
    case BIRTH = 'BIRTH';
    case WEANING = 'WEANING';
    case CONTROL = 'CONTROL';
    case BATCH_CONTROL = 'BATCH_CONTROL';
    case PRE_SERVICE = 'PRE_SERVICE';
    case PREGNANCY_CHECK = 'PREGNANCY_CHECK';
    case ENTRY = 'ENTRY';
    case EXIT = 'EXIT';
    case EMERGENCY = 'EMERGENCY';

    /**
     * Whether the weighing measures biological growth on this farm and so takes part in the
     * daily gain (ADPV). Entry and exit weights carry transport and shrink; a pregnancy check
     * weighs for inventory.
     */
    public function affectsAdpv(): bool
    {
        return match ($this) {
            self::BIRTH, self::WEANING, self::CONTROL, self::BATCH_CONTROL,
            self::PRE_SERVICE, self::EMERGENCY => true,
            self::PREGNANCY_CHECK, self::ENTRY, self::EXIT => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::BIRTH => 'Pesaje al Nacer',
            self::WEANING => 'Pesaje al Destete',
            self::CONTROL => 'Control Individual (ADPV)',
            self::BATCH_CONTROL => 'Repesaje de Lote en Jaula',
            self::PRE_SERVICE => 'Evaluación Pre-Entore',
            self::PREGNANCY_CHECK => 'Pesaje en Tacto / Diagnóstico',
            self::ENTRY => 'Recepción / Ingreso de Tropa',
            self::EXIT => 'Despacho / Venta / Faena',
            self::EMERGENCY => 'Contingencia / Sequía',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::BIRTH => 'Primer peso del ternero, dentro de las primeras 24 h de vida, con balanza portátil en el potrero.',
            self::WEANING => 'Peso al corte de lactancia (6 a 8 meses), base del ajuste a 205 días.',
            self::CONTROL => 'Control periódico de la ganancia diaria de peso vivo, animal por animal en la manga.',
            self::BATCH_CONTROL => 'Pesada del lote por grupos en jaula o báscula de camión; el promedio se asigna a cada caravana.',
            self::PRE_SERVICE => 'Peso crítico de entore en vaquillonas (60 a 65 % del peso adulto).',
            self::PREGNANCY_CHECK => 'Pesaje aprovechando el paso por la manga en el diagnóstico de preñez; no entra en la curva de ganancia.',
            self::ENTRY => 'Pesaje al arribo de una compra; fija la línea base y la merma contra el DTE. No entra en la curva de ganancia.',
            self::EXIT => 'Pesaje de liquidación previo al embarque, con desbaste. No entra en la curva de ganancia.',
            self::EMERGENCY => 'Pesaje extraordinario por sequía o crisis forrajera (destete precoz o hiperprecoz).',
        };
    }

    /**
     * Whether the purpose can be measured on one animal. A cage re-weighing measures a group by
     * definition: offering it on a single caravan would make one animal "average" itself.
     */
    public function allowsIndividualWeighing(): bool
    {
        return $this !== self::BATCH_CONTROL;
    }

    /**
     * Whether the purpose can be measured on a batch as a whole (cage or truck scale). Birth is
     * weighed calf by calf in the paddock; an individual control exists to follow each animal.
     */
    public function allowsGroupWeighing(): bool
    {
        return $this !== self::BIRTH && $this !== self::CONTROL;
    }

    public function defaultMethod(): WeighingMethod
    {
        return match ($this) {
            self::BIRTH => WeighingMethod::PORTABLE_FIELD,
            self::BATCH_CONTROL, self::ENTRY => WeighingMethod::AVERAGE,
            default => WeighingMethod::INDIVIDUAL,
        };
    }

    public function defaultShrunkCondition(): ShrunkCondition
    {
        return $this === self::EXIT ? ShrunkCondition::SHRUNK_12H : ShrunkCondition::FULL_WEIGHT;
    }

    /** Zootechnical order of the productive cycle, used to sort every list of types. */
    public function displayOrder(): int
    {
        return array_search($this, self::cases(), true) + 1;
    }
}
