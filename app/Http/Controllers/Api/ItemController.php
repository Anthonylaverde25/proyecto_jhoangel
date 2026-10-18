<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\DTOs\ItemCatalog\ItemDTO;
use App\Application\UseCases\ItemCatalog\ItemUseCases;
use App\Application\UseCases\Stock\StockUseCases;
use App\Core\Entities\ItemEntity;
use App\Core\ValueObjects\ItemFilters;
use App\Http\Controllers\Api\Concerns\ResolvesActiveCompany;
use App\Http\Controllers\Controller;
use App\Http\Requests\ItemCatalog\SetActiveRequest;
use App\Http\Requests\ItemCatalog\StoreItemRequest;
use App\Http\Requests\ItemCatalog\SyncItemProvidersRequest;
use App\Http\Resources\ItemDetailResource;
use App\Http\Resources\ItemResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ItemController extends Controller
{
    use ResolvesActiveCompany;

    public function __construct(
        private readonly ItemUseCases $items,
        private readonly StockUseCases $stock,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $companyId = $this->activeCompanyId();
        $items = ($this->items->search)(ItemFilters::fromArray($request->query()), $companyId);
        $context = ($this->stock->summarizeItems)(array_map(fn (ItemEntity $item) => (int) $item->getId(), $items), $companyId);

        // "Con alertas": only items with an open negative-balance or critical-stock notice.
        if ($request->boolean('stock_alert')) {
            $items = array_values(array_filter($items, function (ItemEntity $item) use ($context) {
                $stock = $context['stock'][(int) $item->getId()] ?? null;

                return $stock !== null && ($stock['has_negative'] || $stock['has_critical']);
            }));
        }

        return response()->json(array_map(
            fn (ItemEntity $item) => (new ItemResource($item))
                ->withStock($context['stock'][(int) $item->getId()] ?? null, $context['vat'][(int) $item->getId()] ?? null)
                ->resolve($request),
            $items,
        ));
    }

    public function show(int $id): JsonResponse
    {
        return response()->json($this->detail(($this->items->find)($id, $this->activeCompanyId())));
    }

    public function store(StoreItemRequest $request): JsonResponse
    {
        $item = ($this->items->create)(ItemDTO::fromArray($request->validated()), $this->activeCompanyId());

        return response()->json($this->detail(($this->items->find)((int) $item->getId(), $this->activeCompanyId())), 201);
    }

    public function update(StoreItemRequest $request, int $id): JsonResponse
    {
        ($this->items->update)($id, ItemDTO::fromArray($request->validated()), $this->activeCompanyId());

        return response()->json($this->detail(($this->items->find)($id, $this->activeCompanyId())));
    }

    public function setActive(SetActiveRequest $request, int $id): JsonResponse
    {
        ($this->items->setActive)($id, $request->boolean('is_active'), $this->activeCompanyId());

        return response()->json($this->detail(($this->items->find)($id, $this->activeCompanyId())));
    }

    public function syncProviders(SyncItemProvidersRequest $request, int $id): JsonResponse
    {
        $item = ($this->items->syncProviders)($id, $request->validated('providers'), $this->activeCompanyId());

        return response()->json($this->detail($item));
    }

    /** The item file with what B2 adds: movements (frozen fields) and last purchase per provider. */
    private function detail(ItemEntity $item): ItemDetailResource
    {
        return (new ItemDetailResource($item))->withUsage(($this->stock->describeItem)((int) $item->getId(), $this->activeCompanyId()));
    }
}
