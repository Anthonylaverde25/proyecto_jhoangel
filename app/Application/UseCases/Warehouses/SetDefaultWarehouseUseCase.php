<?php

declare(strict_types=1);

namespace App\Application\UseCases\Warehouses;

use App\Core\Interfaces\IWarehouseRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Makes an active warehouse the default; the previous one stops being it (DD2). From now on every
 * provider document enters there (DD5).
 */
final class SetDefaultWarehouseUseCase
{
    public function __construct(
        private readonly IWarehouseRepository $warehouses,
        private readonly ListWarehousesUseCase $list,
    ) {
    }

    public function __invoke(int $id, int $companyId): WarehouseListing
    {
        $warehouse = $this->warehouses->findById($id, $companyId)
            ?? throw new ModelNotFoundException("Warehouse {$id} not found.");
        $warehouse->asDefault();
        $this->warehouses->swapDefault($companyId, $id);

        return ($this->list)($companyId);
    }
}
