<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\UseCases\Caravans\ListBirthHistoryUseCase;
use App\Http\Controllers\Controller;
use App\Http\Resources\BirthHistoryResource;
use Illuminate\Http\JsonResponse;

final class BirthController extends Controller
{
    public function __construct(
        private readonly ListBirthHistoryUseCase $listBirthHistory
    ) {
    }

    /**
     * Lista el historial de partos exitosos y estado de lactancia.
     */
    public function index(): JsonResponse
    {
        $entities = ($this->listBirthHistory)();

        return response()->json(
            BirthHistoryResource::collection($entities)
        );
    }
}
