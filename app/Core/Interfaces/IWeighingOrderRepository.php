<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

use App\Core\Entities\WeighingOrderEntity;

interface IWeighingOrderRepository
{
    /** Persists a new order with its loads, lines and first history entry; returns it with its id. */
    public function create(WeighingOrderEntity $order, ?int $userId): WeighingOrderEntity;

    /** Writes back the status of each line: which weight fulfilled it. */
    public function saveAnimalLines(WeighingOrderEntity $order): void;

    public function findById(int $id, int $companyId): ?WeighingOrderEntity;

    /** @return WeighingOrderEntity[] newest first */
    public function findByBatch(int $batchId, int $companyId): array;

    public function lastCodeWithPrefix(string $prefix, int $companyId): ?string;
}
