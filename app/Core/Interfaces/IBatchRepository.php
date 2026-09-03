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
     * @return BatchEntity[]
     */
    public function findByFarmId(int $farmId, ?string $batchType = null): array;

    public function save(BatchEntity $batch): BatchEntity;

    public function delete(int $id): bool;

    public function addWeight(int $batchId, float $weight, string $type, \DateTimeInterface $date, ?int $activityId = null): void;

    public function getWeights(int $batchId): array;

    public function findSystemBatchByType(string $typeCode): ?BatchEntity;
}

