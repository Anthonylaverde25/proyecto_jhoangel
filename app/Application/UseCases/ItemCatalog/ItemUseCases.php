<?php

declare(strict_types=1);

namespace App\Application\UseCases\ItemCatalog;

final class ItemUseCases
{
    public function __construct(
        public readonly SearchItemsUseCase $search,
        public readonly FindItemUseCase $find,
        public readonly CreateItemUseCase $create,
        public readonly UpdateItemUseCase $update,
        public readonly SetItemActiveUseCase $setActive,
        public readonly AddItemPresentationUseCase $addPresentation,
        public readonly UpdateItemPresentationUseCase $updatePresentation,
        public readonly SetItemPresentationActiveUseCase $setPresentationActive,
        public readonly SyncItemProvidersUseCase $syncProviders,
    ) {
    }
}
