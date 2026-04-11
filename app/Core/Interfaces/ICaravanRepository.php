<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

use App\Core\Entities\CaravanEntity;
use App\Core\ValueObjects\CaravanNumber;

interface ICaravanRepository
{
    /**
     * @param CaravanEntity $caravan
     * @return CaravanEntity
     */
    public function save(CaravanEntity $caravan): CaravanEntity;

    /**
     * @param CaravanNumber $identification
     * @return CaravanEntity|null
     */
    public function findByIdentification(CaravanNumber $identification): ?CaravanEntity;

    /**
     * @param int $id
     * @return CaravanEntity|null
     */
    public function findById(int $id): ?CaravanEntity;

    /**
     * @return CaravanEntity[]
     */
    public function findAll(): array;

    /**
     * @param int $id
     * @return bool
     */
    public function delete(int $id): bool;
}
