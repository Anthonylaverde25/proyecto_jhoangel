<?php

declare(strict_types=1);

namespace App\Application\UseCases\Warehouses;

use App\Core\Interfaces\IWarehouseRepository;
use App\Core\ValueObjects\WarehouseCode;

/**
 * The code proposed for a new warehouse (DD1): the next free ALM-NN. The user may change it.
 */
final class SuggestWarehouseCodeUseCase
{
    public function __construct(
        private readonly IWarehouseRepository $warehouses
    ) {
    }

    public function __invoke(int $companyId): string
    {
        return WarehouseCode::next($this->warehouses->codes($companyId));
    }
}
