<?php

declare(strict_types=1);

namespace App\Application\UseCases\ItemCatalog;

use App\Core\Entities\ItemCategoryEntity;
use App\Core\Interfaces\IItemCategoryRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * C4: an inactive category is no longer offered for new items; its children and items stay as they are.
 */
final class SetItemCategoryActiveUseCase
{
    public function __construct(
        private readonly IItemCategoryRepository $repository
    ) {
    }

    public function __invoke(int $id, bool $active, int $companyId): ItemCategoryEntity
    {
        $category = $this->repository->findById($id, $companyId)
            ?? throw new ModelNotFoundException("Item category {$id} not found.");

        $category->setActive($active);

        return $this->repository->save($category);
    }
}
