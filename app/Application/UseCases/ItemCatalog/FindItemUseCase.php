<?php

declare(strict_types=1);

namespace App\Application\UseCases\ItemCatalog;

use App\Core\Entities\ItemEntity;
use App\Core\Interfaces\IItemRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * An item of another company is simply not found (404), never forbidden: its existence stays hidden.
 */
final class FindItemUseCase
{
    public function __construct(
        private readonly IItemRepository $repository
    ) {
    }

    public function __invoke(int $id, int $companyId): ItemEntity
    {
        return $this->repository->findById($id, $companyId)
            ?? throw new ModelNotFoundException("Item {$id} not found.");
    }
}
