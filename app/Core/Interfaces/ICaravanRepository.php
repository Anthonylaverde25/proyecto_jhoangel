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

    public function findByIdentification(CaravanNumber $identification): ?CaravanEntity;

    /**
     * @param CaravanNumber $identification
     * @return CaravanEntity|null
     */
    public function findByIdentificationGlobal(CaravanNumber $identification): ?CaravanEntity;

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
     * @param int $batchId
     * @return int
     */
    public function countByBatch(int $batchId): int;

    /**
     * @param int $batchId
     * @return float|null
     */
    public function getAverageWeightByBatch(int $batchId): ?float;

    /**
     * @param int $id
     * @return bool
     */
    public function delete(int $id): bool;
}
