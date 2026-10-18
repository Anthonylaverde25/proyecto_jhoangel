<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\DTOs\ItemCatalog\ItemPresentationDTO;
use App\Application\UseCases\ItemCatalog\ItemUseCases;
use App\Http\Controllers\Api\Concerns\ResolvesActiveCompany;
use App\Http\Controllers\Controller;
use App\Http\Requests\ItemCatalog\SetActiveRequest;
use App\Http\Requests\ItemCatalog\StoreItemPresentationRequest;
use App\Http\Resources\ItemPresentationResource;
use Illuminate\Http\JsonResponse;

class ItemPresentationController extends Controller
{
    use ResolvesActiveCompany;

    public function __construct(
        private readonly ItemUseCases $items
    ) {
    }

    public function store(StoreItemPresentationRequest $request, int $id): JsonResponse
    {
        $presentation = ($this->items->addPresentation)($id, ItemPresentationDTO::fromArray($request->validated()), $this->activeCompanyId());

        return response()->json(new ItemPresentationResource($presentation), 201);
    }

    public function update(StoreItemPresentationRequest $request, int $id, int $presentationId): JsonResponse
    {
        $presentation = ($this->items->updatePresentation)(
            $id,
            $presentationId,
            ItemPresentationDTO::fromArray($request->validated()),
            $this->activeCompanyId()
        );

        return response()->json(new ItemPresentationResource($presentation));
    }

    public function setActive(SetActiveRequest $request, int $id, int $presentationId): JsonResponse
    {
        $presentation = ($this->items->setPresentationActive)($id, $presentationId, $request->boolean('is_active'), $this->activeCompanyId());

        return response()->json(new ItemPresentationResource($presentation));
    }
}
