<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

use App\Core\Entities\CaravanMovementEntity;

interface ICaravanMovementRepository
{
    public function save(CaravanMovementEntity $movement): CaravanMovementEntity;

    /**
     * @param int $caravanId
     * @return CaravanMovementEntity[]
     */
    public function findByCaravanId(int $caravanId): array;

    /**
     * @param int $limit
     * @return CaravanMovementEntity[]
     */
    public function findAll(int $limit = 100): array;
}
