<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\UseCases\Stock\StockUseCases;
use App\Core\ValueObjects\KardexFilters;
use App\Http\Controllers\Api\Concerns\ResolvesActiveCompany;
use App\Http\Controllers\Controller;
use App\Http\Requests\Stock\SetCriticalStockRequest;
use App\Http\Resources\ItemStockResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Stock and Kardex tabs of an item.
 */
class ItemStockController extends Controller
{
    use ResolvesActiveCompany;

    public function __construct(
        private readonly StockUseCases $stock
    ) {
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return response()->json((new ItemStockResource(($this->stock->itemStock)($id, $this->activeCompanyId())))->resolve($request));
    }

    public function kardex(Request $request, int $id): JsonResponse
    {
        $filters = KardexFilters::fromArray($request->query());
        $page = ($this->stock->kardex)($id, $filters, $this->activeCompanyId());

        return response()->json($page + ['page' => $filters->page, 'per_page' => $filters->perPage]);
    }

    public function setCritical(SetCriticalStockRequest $request, int $id, int $warehouseId): JsonResponse
    {
        $level = $request->validated('critical_stock');
        $view = ($this->stock->setCritical)($id, $warehouseId, $level === null ? null : (string) $level, $this->activeCompanyId(), $request->user()?->id);

        return response()->json((new ItemStockResource($view))->resolve($request));
    }
}
