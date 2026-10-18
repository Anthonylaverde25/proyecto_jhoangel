<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Mappers\StockMapper;
use App\Core\Entities\WarehouseEntity;
use App\Core\Interfaces\IWarehouseRepository;
use App\Models\Warehouse;

/**
 * Every query filters by company explicitly (D15, layer 2): the company bootstrap runs without a
 * request context.
 */
class EloquentWarehouseRepository implements IWarehouseRepository
{
    public function findDefault(int $companyId): ?WarehouseEntity
    {
        $model = Warehouse::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('is_default', true)
            ->first();

        return $model ? StockMapper::warehouseToEntity($model) : null;
    }

    public function findAll(int $companyId): array
    {
        return Warehouse::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get()
            ->map(fn (Warehouse $model) => StockMapper::warehouseToEntity($model))
            ->all();
    }

    public function ensureDefault(int $companyId): WarehouseEntity
    {
        $existing = $this->findDefault($companyId);
        if ($existing !== null) {
            return $existing;
        }

        $model = Warehouse::withoutGlobalScopes()->create([
            'company_id' => $companyId,
            'code' => WarehouseEntity::DEFAULT_CODE,
            'name' => 'Almacén principal',
            'is_default' => true,
            'is_active' => true,
        ]);

        return StockMapper::warehouseToEntity($model);
    }
}
