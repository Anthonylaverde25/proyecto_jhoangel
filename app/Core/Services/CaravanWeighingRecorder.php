<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Entities\CaravanWeightEntity;
use App\Core\Exceptions\AnimalDeathException;
use App\Core\Interfaces\ICaravanDeathRepository;
use App\Core\Interfaces\ICaravanWeightRepository;
use App\Core\ValueObjects\WeighingReading;

/**
 * The only way a weight reaches the history of an animal.
 *
 * It owns the rule of the current weight: a reading is current only if no other weighing of the
 * animal is dated after it. A weighing loaded late (a sheet scanned a week after, a registered
 * order) joins the history without displacing a newer one, so the batch is never recomputed over
 * an older number.
 *
 * It does not recompute batches: the caller knows whether the event measured the same set or
 * changed it, and recomputes once per batch with that cause.
 */
final class CaravanWeighingRecorder
{
    public function __construct(
        private readonly ICaravanWeightRepository $weights,
        private readonly ICaravanDeathRepository $deaths,
    ) {
    }

    /**
     * @throws AnimalDeathException a weighing dated after the animal died
     */
    public function record(int $caravanId, WeighingReading $reading): CaravanWeightEntity
    {
        // A weighing loaded late, dated before the death, still belongs to its history.
        $diedOn = $this->deaths->deathDates([$caravanId])[$caravanId] ?? null;
        if ($diedOn !== null && $reading->date->format('Y-m-d') > $diedOn) {
            throw AnimalDeathException::weighedAfterDeath($diedOn);
        }

        $isCurrent = !$this->weights->hasWeighingAfter($caravanId, $reading->date);

        if ($isCurrent) {
            $this->weights->markAllNonCurrentForCaravan($caravanId);
        }

        return $this->weights->save(new CaravanWeightEntity(
            id: null,
            caravanId: $caravanId,
            weight: $reading->weight,
            current: $isCurrent,
            weighingDate: $reading->date,
            notes: $reading->notes,
            type: $reading->type,
            method: $reading->method,
            shrunkCondition: $reading->shrunkCondition,
            shrinkPct: $reading->shrinkPct,
            weighingTime: $reading->time,
            source: $reading->source,
            weighingOrderId: $reading->weighingOrderId,
        ));
    }
}
