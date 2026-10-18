<?php

declare(strict_types=1);

namespace App\Application\UseCases\ItemCatalog;

use App\Application\DTOs\ItemCatalog\ItemDTO;
use App\Core\Entities\ItemEntity;
use App\Core\Exceptions\ItemCatalogException;
use App\Core\Interfaces\IItemRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Edits the item's general data. B2 adds the guard that freezes unit, type and track_stock once the
 * item has document lines.
 */
final class UpdateItemUseCase
{
    public function __construct(
        private readonly IItemRepository $repository,
        private readonly ItemReferenceGuard $references,
    ) {
    }

    public function __invoke(int $id, ItemDTO $dto, int $companyId): ItemEntity
    {
        $item = $this->repository->findById($id, $companyId)
            ?? throw new ModelNotFoundException("Item {$id} not found.");

        $code = $dto->code !== null ? strtoupper($dto->code) : null;
        if ($code !== null && $code !== $item->getCode()) {
            $existing = $this->repository->findByCode($code, $companyId);
            if ($existing !== null) {
                throw ItemCatalogException::duplicateItemCode($code, $existing->getName());
            }
        }

        $item->updateDetails(
            $dto->name ?? $item->getName(),
            $dto->descriptionSent ? $dto->description : $item->getDescription(),
            $code,
        );

        if ($dto->behavior !== null) {
            $item->changeBehavior($dto->behavior);
        }
        if ($dto->unitOfMeasureId !== null && $dto->unitOfMeasureId !== $item->getUnitOfMeasureId()) {
            $this->references->assertUnit($dto->unitOfMeasureId, $companyId);
            $item->changeUnit($dto->unitOfMeasureId);
        }
        if ($dto->categorySent && $dto->itemCategoryId !== $item->getItemCategoryId()) {
            $this->references->assertCategory($dto->itemCategoryId, $companyId);
            $item->changeCategory($dto->itemCategoryId);
        }

        return $this->repository->save($item);
    }
}
