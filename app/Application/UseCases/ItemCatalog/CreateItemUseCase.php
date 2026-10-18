<?php

declare(strict_types=1);

namespace App\Application\UseCases\ItemCatalog;

use App\Application\DTOs\ItemCatalog\ItemDTO;
use App\Core\Entities\ItemEntity;
use App\Core\Enums\ItemBehavior;
use App\Core\Exceptions\ItemCatalogException;
use App\Core\Interfaces\IItemRepository;

/**
 * Creates an article or a service. Without a code, the company's next ART-/SRV- code is assigned (Q1).
 */
final class CreateItemUseCase
{
    public function __construct(
        private readonly IItemRepository $repository,
        private readonly ItemReferenceGuard $references,
    ) {
    }

    public function __invoke(ItemDTO $dto, int $companyId): ItemEntity
    {
        $behavior = $dto->behavior ?? ItemBehavior::STOCKED_ARTICLE;
        $this->references->assertUnit((int) $dto->unitOfMeasureId, $companyId);
        $this->references->assertCategory($dto->itemCategoryId, $companyId);

        $code = $dto->code !== null ? strtoupper($dto->code) : null;
        if ($code !== null && ($existing = $this->repository->findByCode($code, $companyId)) !== null) {
            throw ItemCatalogException::duplicateItemCode($code, $existing->getName());
        }

        $item = new ItemEntity(
            null,
            $companyId,
            $code,
            (string) $dto->name,
            $dto->description,
            $behavior->type(),
            $behavior->tracksStock(),
            (int) $dto->unitOfMeasureId,
            $dto->itemCategoryId,
        );

        return $code === null ? $this->repository->saveWithGeneratedCode($item) : $this->repository->save($item);
    }
}
