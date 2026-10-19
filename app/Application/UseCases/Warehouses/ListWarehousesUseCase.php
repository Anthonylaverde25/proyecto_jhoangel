<?php

declare(strict_types=1);

namespace App\Application\UseCases\Warehouses;

use App\Core\Interfaces\IWarehouseRepository;

/**
 * Every warehouse of the company, active or not, with its summary. Makes sure the default exists (I11).
 */
final class ListWarehousesUseCase
{
    public function __construct(
        private readonly IWarehouseRepository $warehouses
    ) {
    }

    public function __invoke(int $companyId): WarehouseListing
    {
        $this->warehouses->ensureDefault($companyId);

        return new WarehouseListing($this->warehouses->findAll($companyId), $this->warehouses->summaries($companyId));
    }
}
