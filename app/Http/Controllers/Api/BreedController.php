<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use App\Core\Interfaces\IBreedRepository;
use Illuminate\Http\JsonResponse;

class BreedController extends Controller
{
    public function __construct(
        private readonly IBreedRepository $breedRepository
    ) {
    }

    public function index(): JsonResponse
    {
        $entities = $this->breedRepository->getAll();
        
        $breeds = array_map(fn($entity) => [
            'id' => $entity->getId(),
            'name' => $entity->getName(),
        ], $entities);

        return response()->json(['data' => $breeds]);
    }
}
