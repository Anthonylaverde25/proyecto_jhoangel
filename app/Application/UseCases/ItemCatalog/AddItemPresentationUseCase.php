<?php

declare(strict_types=1);

namespace App\Application\UseCases\ItemCatalog;

use App\Application\DTOs\ItemCatalog\ItemPresentationDTO;
use App\Core\Entities\ItemPresentationEntity;
use App\Core\Exceptions\ItemCatalogException;
use App\Core\Interfaces\IItemRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class AddItemPresentationUseCase
{
    public function __construct(
        private readonly IItemRepository $repository
    ) {
    }

    public function __invoke(int $itemId, ItemPresentationDTO $dto, int $companyId): ItemPresentationEntity
    {
        $item = $this->repository->findById($itemId, $companyId)
            ?? throw new ModelNotFoundException("Item {$itemId} not found.");

        if (!$item->isActive()) {
            throw ItemCatalogException::inactiveItem($item->getName());
        }
        if ($this->repository->presentationNameExists($itemId, (string) $dto->name)) {
            throw ItemCatalogException::duplicatePresentation($item->getName(), (string) $dto->name);
        }

        return $this->repository->savePresentation(
            new ItemPresentationEntity(null, $itemId, (string) $dto->name, (string) $dto->factorToItemUnit)
        );
    }
}
