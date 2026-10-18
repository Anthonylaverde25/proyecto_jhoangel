<?php

declare(strict_types=1);

namespace App\Application\UseCases\Stock;

use App\Core\Interfaces\IWarehouseRepository;

/**
 * Read-only in B2 (DS1): every company works with its MAIN warehouse.
 */
final class ListWarehousesUseCase
{
    public function __construct(
        private readonly IWarehouseRepository $warehouses
    ) {
    }

    /** @return list<\App\Core\Entities\WarehouseEntity> */
    public function __invoke(int $companyId): array
    {
        $this->warehouses->ensureDefault($companyId);

        return $this->warehouses->findAll($companyId);
    }
}
