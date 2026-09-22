<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

use App\Core\Entities\BatchEntity;

interface IBatchRepository
{
    /**
     * @return BatchEntity[]
     */
    public function findAll(?string $batchType = null, ?string $scope = null): array;


    public function findById(int $id): ?BatchEntity;

    public function findByNameAndFarmId(string $name, int $farmId): ?BatchEntity;

    /**
     * Active batch of the current company with exactly this name, if any.
     */
    public function findActiveByName(string $name): ?BatchEntity;

    /**
     * @return BatchEntity[]
     */
    public function findByFarmId(int $farmId, ?string $batchType = null): array;

    public function save(BatchEntity $batch): BatchEntity;

    public function delete(int $id): bool;

    /**
     * Records a point in the weight series of a batch.
     *
     * `$weight` is nullable on purpose: the average of an empty batch is undefined, and
     * writing a zero there would state that the animals weigh nothing.
     */
    public function addWeight(
        int $batchId,
        ?float $weight,
        string $type,
        \DateTimeInterface $date,
        ?int $activityId = null,
        ?float $totalWeight = null,
        ?int $caravansCount = null,
        ?int $weighedCount = null,
        ?\DateTimeInterface $weightsAsOf = null
    ): void;

    /** Most recent point of the weight series of a batch, by date and then by insertion. */
    public function findLatestWeight(int $batchId): ?\App\Core\Entities\BatchWeightEntity;

    public function getWeights(int $batchId): array;

    public function findSystemBatchByType(string $typeCode): ?BatchEntity;
}

