<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

use App\Core\Entities\CaravanWeightEntity;

interface ICaravanWeightRepository
{
    public function save(CaravanWeightEntity $weight): CaravanWeightEntity;

    public function findCurrentByCaravanId(int $caravanId): ?CaravanWeightEntity;

    /**
     * @param int $caravanId
     * @return CaravanWeightEntity[]
     */
    public function findByCaravanId(int $caravanId): array;

    public function markAllNonCurrentForCaravan(int $caravanId): void;

    /**
     * Whether the animal already has a weighing dated after this day: a weighing loaded late
     * then belongs to the history and must not displace the current one.
     */
    public function hasWeighingAfter(int $caravanId, \DateTimeInterface $date): bool;
}
