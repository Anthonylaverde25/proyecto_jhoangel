<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

use App\Core\Entities\StockIncidentEntity;
use App\Core\Enums\StockIncidentStatus;
use App\Core\Enums\StockIncidentType;

interface IStockIncidentRepository
{
    public function findOpen(int $warehouseId, int $itemId, StockIncidentType $type, int $companyId): ?StockIncidentEntity;

    public function save(StockIncidentEntity $incident): StockIncidentEntity;

    /** @return list<StockIncidentEntity> newest first */
    public function search(int $companyId, ?StockIncidentStatus $status, ?int $itemId): array;

    public function findById(int $id, int $companyId): ?StockIncidentEntity;
}
