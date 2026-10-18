<?php

declare(strict_types=1);

namespace App\Application\UseCases\ItemCatalog;

use App\Core\Entities\ItemEntity;
use App\Core\Interfaces\IItemRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * An inactive item stays in the catalog but is not offered for new documents. B2 adds the guard
 * against deactivating an item with a balance (I10).
 */
final class SetItemActiveUseCase
{
    public function __construct(
        private readonly IItemRepository $repository
    ) {
    }

    public function __invoke(int $id, bool $active, int $companyId): ItemEntity
    {
        $item = $this->repository->findById($id, $companyId)
            ?? throw new ModelNotFoundException("Item {$id} not found.");

        $item->setActive($active);

        return $this->repository->save($item);
    }
}
