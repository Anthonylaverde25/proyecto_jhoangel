<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\DTOs\ItemCatalog\ItemDTO;
use App\Application\UseCases\ItemCatalog\ItemUseCases;
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
        private readonly ItemUseCases $items
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $items = ($this->items->search)(ItemFilters::fromArray($request->query()), $this->activeCompanyId());

        return response()->json(ItemResource::collection($items));
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(new ItemDetailResource(($this->items->find)($id, $this->activeCompanyId())));
    }

    public function store(StoreItemRequest $request): JsonResponse
    {
        $item = ($this->items->create)(ItemDTO::fromArray($request->validated()), $this->activeCompanyId());

        return response()->json(new ItemDetailResource(($this->items->find)((int) $item->getId(), $this->activeCompanyId())), 201);
    }

    public function update(StoreItemRequest $request, int $id): JsonResponse
    {
        ($this->items->update)($id, ItemDTO::fromArray($request->validated()), $this->activeCompanyId());

        return response()->json(new ItemDetailResource(($this->items->find)($id, $this->activeCompanyId())));
    }

    public function setActive(SetActiveRequest $request, int $id): JsonResponse
    {
        ($this->items->setActive)($id, $request->boolean('is_active'), $this->activeCompanyId());

        return response()->json(new ItemDetailResource(($this->items->find)($id, $this->activeCompanyId())));
    }

    public function syncProviders(SyncItemProvidersRequest $request, int $id): JsonResponse
    {
        $item = ($this->items->syncProviders)($id, $request->validated('providers'), $this->activeCompanyId());

        return response()->json(new ItemDetailResource($item));
    }
}
