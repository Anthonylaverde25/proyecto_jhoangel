<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Application\UseCases\Castrations\ListCaravanCategoryChangesUseCase;
use App\Core\Interfaces\ICompanyContext;
use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryChangeResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class CaravanCategoryChangeController extends Controller
{
    public function __construct(
        private readonly ICompanyContext $companyContext
    ) {
    }

    public function index(Request $request, int $id, ListCaravanCategoryChangesUseCase $useCase): AnonymousResourceCollection
    {
        $companyId = (int) $this->companyContext->getCompanyId();
        $entities = $useCase($companyId, $id);

        return CategoryChangeResource::collection($entities);
    }
}

