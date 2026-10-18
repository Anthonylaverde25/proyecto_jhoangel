<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\DTOs\ItemCatalog\ItemCategoryDTO;
use App\Application\UseCases\ItemCatalog\ItemCategoryUseCases;
use App\Http\Controllers\Api\Concerns\ResolvesActiveCompany;
use App\Http\Controllers\Controller;
use App\Http\Requests\ItemCatalog\SetActiveRequest;
use App\Http\Requests\ItemCatalog\StoreItemCategoryRequest;
use App\Http\Requests\ItemCatalog\UpdateItemCategoryRequest;
use App\Http\Resources\ItemCategoryResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ItemCategoryController extends Controller
{
    use ResolvesActiveCompany;

    public function __construct(
        private readonly ItemCategoryUseCases $categories
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $categories = ($this->categories->list)($this->activeCompanyId(), $request->boolean('include_inactive'));

        return response()->json(ItemCategoryResource::collection($categories));
    }

    public function store(StoreItemCategoryRequest $request): JsonResponse
    {
        $category = ($this->categories->create)(ItemCategoryDTO::fromArray($request->validated()), $this->activeCompanyId());

        return response()->json(new ItemCategoryResource($category), 201);
    }

    public function update(UpdateItemCategoryRequest $request, int $id): JsonResponse
    {
        $category = ($this->categories->update)($id, ItemCategoryDTO::fromArray($request->validated()), $this->activeCompanyId());

        return response()->json(new ItemCategoryResource($category));
    }

    public function setActive(SetActiveRequest $request, int $id): JsonResponse
    {
        $category = ($this->categories->setActive)($id, $request->boolean('is_active'), $this->activeCompanyId());

        return response()->json(new ItemCategoryResource($category));
    }
}
