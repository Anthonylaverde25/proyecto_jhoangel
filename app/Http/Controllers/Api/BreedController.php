<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\UseCases\Breeds\BreedUseCases;
use App\Http\Controllers\Controller;
use App\Http\Resources\BreedResource;
use Illuminate\Http\JsonResponse;

class BreedController extends Controller
{
    public function __construct(
        private readonly BreedUseCases $breed
    ) {
    }

    /**
     * Lista todas las razas disponibles.
     */
    public function index(): JsonResponse
    {
        $entities = ($this->breed->list)();
        
        return response()->json([
            'data' => BreedResource::collection($entities)
        ]);
    }
}
