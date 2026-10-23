<?php

declare(strict_types=1);

namespace App\Application\UseCases\Warehouses;

use App\Core\Entities\WarehouseEntity;
use App\Core\Enums\WarehouseKind;
use App\Core\Interfaces\IWarehouseRepository;
use App\Core\ValueObjects\WarehouseCode;

/**
 * A new warehouse or semen tank: active, and the default of its kind only when that kind has none
 * yet (DD1, SW4).
 */
final class CreateWarehouseUseCase
{
    public function __construct(
        private readonly IWarehouseRepository $warehouses,
        private readonly WarehouseUniqueness $uniqueness,
    ) {
    }

    public function __invoke(string $code, string $name, WarehouseKind $kind, int $companyId): WarehouseEntity
    {
        $code = (new WarehouseCode($code))->value;
        $name = WarehouseEntity::cleanName($name);
        $this->uniqueness->check($companyId, $code, $name);

        return $this->warehouses->create($companyId, $code, $name, $kind);
    }
}
