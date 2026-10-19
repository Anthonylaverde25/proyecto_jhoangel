<?php

declare(strict_types=1);

namespace App\Application\Services\Stock;

use App\Core\Entities\WarehouseEntity;
use App\Core\Interfaces\IWarehouseRepository;

/**
 * The warehouse an internal operation declares (DD6): the one asked for, or the company's default
 * when none comes. Initial stock, consumption and transfers only take an active one (R4).
 */
final class WarehouseGuard
{
    public function __construct(
        private readonly IWarehouseRepository $warehouses,
    ) {
    }

    /** Null, with the error added under $field, when it is not the company's or it is inactive. */
    public function active(int $companyId, ?int $warehouseId, string $field, FieldErrorBag $errors): ?WarehouseEntity
    {
        $warehouse = $warehouseId === null
            ? $this->warehouses->ensureDefault($companyId)
            : $this->warehouses->findById($warehouseId, $companyId);

        if ($warehouse === null) {
            $errors->add($field, 'El almacén no existe en esta empresa.');

            return null;
        }
        if (!$warehouse->isActive) {
            $errors->add($field, "El almacén {$warehouse->code} está inactivo.");

            return null;
        }

        return $warehouse;
    }
}
