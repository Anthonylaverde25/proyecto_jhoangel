<?php

declare(strict_types=1);

namespace App\Application\UseCases\ItemCatalog;

final class ItemCategoryUseCases
{
    public function __construct(
        public readonly ListItemCategoriesUseCase $list,
        public readonly CreateItemCategoryUseCase $create,
        public readonly UpdateItemCategoryUseCase $update,
        public readonly SetItemCategoryActiveUseCase $setActive,
    ) {
    }
}
