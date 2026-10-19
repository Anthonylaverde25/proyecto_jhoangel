<?php

declare(strict_types=1);

namespace App\Application\UseCases\Warehouses;

use App\Core\Entities\WarehouseEntity;
use App\Core\Interfaces\IWarehouseRepository;
use App\Core\ValueObjects\WarehouseCode;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Renames a warehouse: code and name only (DD1).
 */
final class UpdateWarehouseUseCase
{
    public function __construct(
        private readonly IWarehouseRepository $warehouses,
        private readonly WarehouseUniqueness $uniqueness,
    ) {
    }

    public function __invoke(int $id, string $code, string $name, int $companyId): WarehouseEntity
    {
        $warehouse = $this->warehouses->findById($id, $companyId)
            ?? throw new ModelNotFoundException("Warehouse {$id} not found.");
        $renamed = $warehouse->renamed(new WarehouseCode($code), $name);
        $this->uniqueness->check($companyId, $renamed->code, $renamed->name, $id);

        return $this->warehouses->save($renamed);
    }
}
