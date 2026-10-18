<?php

declare(strict_types=1);

namespace App\Application\UseCases\ItemCatalog;

use App\Core\Entities\ItemPresentationEntity;
use App\Core\Interfaces\IItemRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class SetItemPresentationActiveUseCase
{
    public function __construct(
        private readonly IItemRepository $repository
    ) {
    }

    public function __invoke(int $itemId, int $presentationId, bool $active, int $companyId): ItemPresentationEntity
    {
        $this->repository->findById($itemId, $companyId)
            ?? throw new ModelNotFoundException("Item {$itemId} not found.");
        $presentation = $this->repository->findPresentation($presentationId, $itemId)
            ?? throw new ModelNotFoundException("Presentation {$presentationId} not found.");

        $presentation->setActive($active);

        return $this->repository->savePresentation($presentation);
    }
}
