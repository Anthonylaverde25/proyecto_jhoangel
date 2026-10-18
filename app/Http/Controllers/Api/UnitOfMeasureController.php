<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\DTOs\ItemCatalog\UnitOfMeasureDTO;
use App\Application\UseCases\ItemCatalog\UnitOfMeasureUseCases;
use App\Http\Controllers\Api\Concerns\ResolvesActiveCompany;
use App\Http\Controllers\Controller;
use App\Http\Requests\ItemCatalog\SetActiveRequest;
use App\Http\Requests\ItemCatalog\StoreUnitOfMeasureRequest;
use App\Http\Requests\ItemCatalog\UpdateUnitOfMeasureRequest;
use App\Http\Resources\UnitOfMeasureResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UnitOfMeasureController extends Controller
{
    use ResolvesActiveCompany;

    public function __construct(
        private readonly UnitOfMeasureUseCases $units
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $units = ($this->units->list)($this->activeCompanyId(), $request->boolean('include_inactive'));

        return response()->json(UnitOfMeasureResource::collection($units));
    }

    public function store(StoreUnitOfMeasureRequest $request): JsonResponse
    {
        $unit = ($this->units->create)(UnitOfMeasureDTO::fromArray($request->validated()), $this->activeCompanyId());

        return response()->json(new UnitOfMeasureResource($unit), 201);
    }

    public function update(UpdateUnitOfMeasureRequest $request, int $id): JsonResponse
    {
        $unit = ($this->units->update)($id, UnitOfMeasureDTO::fromArray($request->validated()), $this->activeCompanyId());

        return response()->json(new UnitOfMeasureResource($unit));
    }

    public function setActive(SetActiveRequest $request, int $id): JsonResponse
    {
        $unit = ($this->units->setActive)($id, $request->boolean('is_active'), $this->activeCompanyId());

        return response()->json(new UnitOfMeasureResource($unit));
    }
}
