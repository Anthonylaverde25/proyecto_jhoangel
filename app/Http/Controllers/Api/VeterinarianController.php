<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\UseCases\Veterinary\ManageVeterinariansUseCase;
use App\Http\Controllers\Controller;
use App\Http\Requests\Veterinary\StoreVeterinarianRequest;
use App\Http\Resources\VeterinarianResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class VeterinarianController extends Controller
{
    public function index(Request $request, ManageVeterinariansUseCase $useCase): AnonymousResourceCollection
    {
        $activeOnly = !$request->boolean('include_inactive');

        return VeterinarianResource::collection($useCase->list($activeOnly));
    }

    public function store(StoreVeterinarianRequest $request, ManageVeterinariansUseCase $useCase): JsonResponse
    {
        return (new VeterinarianResource($useCase->create($request->validated())))
            ->response()
            ->setStatusCode(201);
    }

    public function update(StoreVeterinarianRequest $request, int $id, ManageVeterinariansUseCase $useCase): JsonResponse
    {
        return (new VeterinarianResource($useCase->update($id, $request->validated())))->response();
    }
}
