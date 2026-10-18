<?php

declare(strict_types=1);

namespace App\Application\UseCases\ItemCatalog;

use App\Core\Entities\ItemEntity;
use App\Core\Interfaces\IItemRepository;
use App\Core\ValueObjects\ItemFilters;

final class SearchItemsUseCase
{
    public function __construct(
        private readonly IItemRepository $repository
    ) {
    }

    /** @return list<ItemEntity> */
    public function __invoke(ItemFilters $filters, int $companyId): array
    {
        return $this->repository->search($filters, $companyId);
    }
}
