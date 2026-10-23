<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\UseCases\Warehouses\WarehouseListing;
use App\Application\UseCases\Warehouses\WarehouseUseCases;
use App\Core\Entities\WarehouseEntity;
use App\Core\Enums\WarehouseKind;
use App\Http\Controllers\Api\Concerns\ResolvesActiveCompany;
use App\Http\Controllers\Controller;
use App\Http\Requests\Warehouses\SetWarehouseActiveRequest;
use App\Http\Requests\Warehouses\StoreWarehouseRequest;
use App\Http\Resources\WarehouseResource;
use App\Http\Resources\WarehouseStockItemResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The company's warehouses (delivery D). Never deleted, only deactivated (DD4). Changing the default
 * or the active flag answers with the whole list: two rows may change at once.
 */
class WarehouseController extends Controller
{
    use ResolvesActiveCompany;

    public function __construct(
        private readonly WarehouseUseCases $warehouses
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        return $this->listing($request, ($this->warehouses->list)($this->activeCompanyId()));
    }

    public function nextCode(): JsonResponse
    {
        return response()->json(['code' => ($this->warehouses->nextCode)($this->activeCompanyId())]);
    }

    public function store(StoreWarehouseRequest $request): JsonResponse
    {
        $warehouse = ($this->warehouses->create)(
            (string) $request->validated('code'),
            (string) $request->validated('name'),
            $request->kind() ?? WarehouseKind::GENERAL,
            $this->activeCompanyId(),
        );

        return $this->one($request, $warehouse, 201);
    }

    public function update(StoreWarehouseRequest $request, int $id): JsonResponse
    {
        $warehouse = ($this->warehouses->update)(
            $id,
            (string) $request->validated('code'),
            (string) $request->validated('name'),
            $request->kind(),
            $this->activeCompanyId(),
        );

        return $this->one($request, $warehouse, 200);
    }

    public function setDefault(Request $request, int $id): JsonResponse
    {
        return $this->listing($request, ($this->warehouses->setDefault)($id, $this->activeCompanyId()));
    }

    public function setActive(SetWarehouseActiveRequest $request, int $id): JsonResponse
    {
        $newDefault = $request->validated('new_default_id');
        $listing = ($this->warehouses->setActive)(
            $id,
            (bool) $request->validated('is_active'),
            $newDefault === null ? null : (int) $newDefault,
            $this->activeCompanyId(),
        );

        return $this->listing($request, $listing);
    }

    public function stock(Request $request, int $id): JsonResponse
    {
        $result = ($this->warehouses->stock)($id, $this->activeCompanyId());

        return response()->json([
            'warehouse' => (new WarehouseResource($result['warehouse']))->resolve($request),
            'items' => array_map(fn (array $row) => WarehouseStockItemResource::fromRow($row), $result['items']),
        ]);
    }

    private function listing(Request $request, WarehouseListing $listing): JsonResponse
    {
        return response()->json(array_map(
            fn (WarehouseEntity $w) => WarehouseResource::withSummary($w, $listing->summaryOf($w->id))->resolve($request),
            $listing->warehouses,
        ));
    }

    private function one(Request $request, WarehouseEntity $warehouse, int $status): JsonResponse
    {
        return response()->json((new WarehouseResource($warehouse))->resolve($request), $status);
    }
}
