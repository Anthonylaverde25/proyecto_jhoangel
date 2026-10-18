<?php

declare(strict_types=1);

namespace App\Application\UseCases\ItemCatalog;

use App\Application\DTOs\ItemCatalog\ItemPresentationDTO;
use App\Core\Entities\ItemPresentationEntity;
use App\Core\Exceptions\ItemCatalogException;
use App\Core\Interfaces\IItemRepository;
use App\Core\Interfaces\IItemUsageReader;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Editable while no document line uses it; name and factor freeze from the first line (P4).
 */
final class UpdateItemPresentationUseCase
{
    public function __construct(
        private readonly IItemRepository $repository,
        private readonly IItemUsageReader $usage,
    ) {
    }

    public function __invoke(int $itemId, int $presentationId, ItemPresentationDTO $dto, int $companyId): ItemPresentationEntity
    {
        $item = $this->repository->findById($itemId, $companyId)
            ?? throw new ModelNotFoundException("Item {$itemId} not found.");
        $presentation = $this->repository->findPresentation($presentationId, $itemId)
            ?? throw new ModelNotFoundException("Presentation {$presentationId} not found.");

        $name = $dto->name ?? $presentation->getName();
        if ($name !== $presentation->getName() && $this->repository->presentationNameExists($itemId, $name, $presentationId)) {
            throw ItemCatalogException::duplicatePresentation($item->getName(), $name);
        }

        $presentation->update(
            $name,
            $dto->factorToItemUnit ?? $presentation->getFactorToItemUnit(),
            $this->usage->presentationHasLines($presentationId),
        );

        return $this->repository->savePresentation($presentation);
    }
}
