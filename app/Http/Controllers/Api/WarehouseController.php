<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\UseCases\Stock\StockUseCases;
use App\Core\Entities\WarehouseEntity;
use App\Http\Controllers\Api\Concerns\ResolvesActiveCompany;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Read-only in B2 (DS1).
 */
class WarehouseController extends Controller
{
    use ResolvesActiveCompany;

    public function __construct(
        private readonly StockUseCases $stock
    ) {
    }

    public function index(): JsonResponse
    {
        return response()->json(array_map(fn (WarehouseEntity $w) => [
            'id' => $w->id,
            'code' => $w->code,
            'name' => $w->name,
            'is_default' => $w->isDefault,
            'is_active' => $w->isActive,
        ], ($this->stock->warehouses)($this->activeCompanyId())));
    }
}
