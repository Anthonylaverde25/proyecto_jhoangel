<?php

declare(strict_types=1);

namespace App\Core\ValueObjects;

use App\Core\Enums\ShrunkCondition;
use App\Core\Enums\WeighingMethod;
use App\Core\Enums\WeighingSource;
use App\Core\Enums\WeighingTypeCode;
use App\Core\Exceptions\InvalidWeighingException;

/**
 * One reading of the scale for one animal, with everything needed to interpret it later: why it
 * was taken, how, and on which gut-fill basis. The kilos are kept exactly as measured.
 */
final readonly class WeighingReading
{
    public function __construct(
        public float $weight,
        public \DateTimeInterface $date,
        public WeighingTypeCode $type,
        public WeighingMethod $method,
        public WeighingSource $source,
        public ShrunkCondition $shrunkCondition = ShrunkCondition::FULL_WEIGHT,
        public ?float $shrinkPct = null,
        public ?string $time = null,
        public ?string $notes = null,
        public ?int $weighingOrderId = null,
    ) {
        if ($weight <= 0) {
            throw new InvalidWeighingException("El peso debe ser mayor a 0 kg (se recibió {$weight}).");
        }

        if ($shrunkCondition->requiresShrinkPct() && ($shrinkPct === null || $shrinkPct <= 0 || $shrinkPct >= 30)) {
            throw new InvalidWeighingException('El desbaste calculado requiere un porcentaje entre 0 y 30.');
        }

        if (!$shrunkCondition->requiresShrinkPct() && $shrinkPct !== null) {
            throw new InvalidWeighingException('El porcentaje de desbaste sólo aplica al desbaste calculado.');
        }

        if ($type === WeighingTypeCode::BATCH_CONTROL && $method !== WeighingMethod::AVERAGE) {
            throw new InvalidWeighingException('El repesaje de lote en jaula sólo existe como promedio del lote.');
        }

        if ($time !== null && preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $time) !== 1) {
            throw new InvalidWeighingException("Hora de pesaje inválida: {$time}.");
        }
    }

    /**
     * A reading with the method and shrink basis the type usually has. For flows that do not ask
     * the operator for them (a weaning, a change of activity).
     */
    public static function typical(
        float $weight,
        \DateTimeInterface $date,
        WeighingTypeCode $type,
        WeighingSource $source,
        ?string $notes = null,
        ?WeighingMethod $method = null,
    ): self {
        return new self(
            weight: $weight,
            date: $date,
            type: $type,
            method: $method ?? WeighingMethod::INDIVIDUAL,
            source: $source,
            shrunkCondition: $type->defaultShrunkCondition(),
            notes: $notes,
        );
    }
}
