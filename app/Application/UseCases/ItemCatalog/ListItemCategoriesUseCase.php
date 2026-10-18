<?php

declare(strict_types=1);

namespace App\Application\UseCases\ItemCatalog;

use App\Core\Entities\ItemCategoryEntity;
use App\Core\Interfaces\IItemCategoryRepository;

/**
 * A flat list: the screen builds the tree.
 */
final class ListItemCategoriesUseCase
{
    public function __construct(
        private readonly IItemCategoryRepository $repository
    ) {
    }

    /** @return list<ItemCategoryEntity> */
    public function __invoke(int $companyId, bool $includeInactive): array
    {
        return $this->repository->findAll($companyId, $includeInactive);
    }
}
