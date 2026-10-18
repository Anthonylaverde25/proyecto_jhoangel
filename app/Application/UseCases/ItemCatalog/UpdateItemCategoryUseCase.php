<?php

declare(strict_types=1);

namespace App\Application\UseCases\ItemCatalog;

use App\Application\DTOs\ItemCatalog\ItemCategoryDTO;
use App\Core\Entities\ItemCategoryEntity;
use App\Core\Exceptions\ItemCatalogException;
use App\Core\Interfaces\IItemCategoryRepository;
use App\Core\Services\CategoryTreeGuard;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class UpdateItemCategoryUseCase
{
    public function __construct(
        private readonly IItemCategoryRepository $repository
    ) {
    }

    public function __invoke(int $id, ItemCategoryDTO $dto, int $companyId): ItemCategoryEntity
    {
        $category = $this->repository->findById($id, $companyId)
            ?? throw new ModelNotFoundException("Item category {$id} not found.");

        $category->update($dto->name ?? $category->getName(), $dto->displayOrder ?? $category->getDisplayOrder());

        if ($dto->parentSent && $dto->parentId !== $category->getParentId()) {
            $this->assertParent($category, $dto->parentId, $companyId);
            $category->moveTo($dto->parentId);
        }

        return $this->repository->save($category);
    }

    /** C2 and C3: an active parent of the company, never the category itself or a descendant. */
    private function assertParent(ItemCategoryEntity $category, ?int $parentId, int $companyId): void
    {
        if ($parentId === null) {
            return;
        }
        $parent = $this->repository->findById($parentId, $companyId)
            ?? throw new ModelNotFoundException("Item category {$parentId} not found.");

        if (CategoryTreeGuard::createsCycle($this->repository->parentMap($companyId), (int) $category->getId(), $parentId)) {
            throw ItemCatalogException::categoryCycle($category->getName(), $parent->getName());
        }
        if (!$parent->isActive()) {
            throw ItemCatalogException::inactiveCategory($parent->getName());
        }
    }
}
