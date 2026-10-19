<?php

declare(strict_types=1);

namespace App\Application\UseCases\Warehouses;

use App\Core\Entities\WarehouseEntity;
use App\Core\Interfaces\IWarehouseRepository;
use App\Core\ValueObjects\WarehouseCode;

/**
 * A new warehouse: active and not the default (DD1).
 */
final class CreateWarehouseUseCase
{
    public function __construct(
        private readonly IWarehouseRepository $warehouses,
        private readonly WarehouseUniqueness $uniqueness,
    ) {
    }

    public function __invoke(string $code, string $name, int $companyId): WarehouseEntity
    {
        $code = (new WarehouseCode($code))->value;
        $name = WarehouseEntity::cleanName($name);
        $this->uniqueness->check($companyId, $code, $name);

        return $this->warehouses->create($companyId, $code, $name);
    }
}
