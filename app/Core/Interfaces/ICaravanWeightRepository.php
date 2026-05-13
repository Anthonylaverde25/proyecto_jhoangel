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
}
