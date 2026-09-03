<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\UseCases\Categories\ListAnimalCategoriesUseCase;
use App\Core\Interfaces\IAnimalCategoryRepository;
use App\Http\Controllers\Controller;
use App\Http\Resources\AnimalCategoryResource;
use App\Http\Resources\AnimalSubcategoryResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AnimalCategoryController extends Controller
{
    /**
     * Get all animal categories with nested subcategories.
     */
    public function index(ListAnimalCategoriesUseCase $listUseCase): AnonymousResourceCollection
    {
        $categories = $listUseCase();

        return AnimalCategoryResource::collection($categories);
    }

    /**
     * Get subcategories for a given category ID.
     */
    public function subcategories(int $id, IAnimalCategoryRepository $categoryRepository): AnonymousResourceCollection
    {
        $subcategories = $categoryRepository->getSubcategoriesByCategoryId($id);

        return AnimalSubcategoryResource::collection($subcategories);
    }
}
