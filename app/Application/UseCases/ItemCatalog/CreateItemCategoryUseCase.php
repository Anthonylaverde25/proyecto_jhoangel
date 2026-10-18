<?php

declare(strict_types=1);

namespace App\Application\UseCases\ItemCatalog;

use App\Application\DTOs\ItemCatalog\ItemCategoryDTO;
use App\Core\Entities\ItemCategoryEntity;
use App\Core\Exceptions\ItemCatalogException;
use App\Core\Interfaces\IItemCategoryRepository;
use Illuminate\Support\Str;

/**
 * The operator thinks in names; the code only has to be stable and unique (C1), so it is derived
 * from the name when none is sent.
 */
final class CreateItemCategoryUseCase
{
    private const CODE_LENGTH = 32;

    public function __construct(
        private readonly IItemCategoryRepository $repository
    ) {
    }

    public function __invoke(ItemCategoryDTO $dto, int $companyId): ItemCategoryEntity
    {
        $this->assertParent($dto->parentId, $companyId);

        return $this->repository->save(new ItemCategoryEntity(
            null,
            $companyId,
            $dto->parentId,
            $dto->code !== null ? strtoupper($dto->code) : $this->codeFromName((string) $dto->name, $companyId),
            (string) $dto->name,
            true,
            $dto->displayOrder ?? 100,
        ));
    }

    private function assertParent(?int $parentId, int $companyId): void
    {
        if ($parentId === null) {
            return;
        }
        $parent = $this->repository->findById($parentId, $companyId);
        if ($parent !== null && !$parent->isActive()) {
            throw ItemCatalogException::inactiveCategory($parent->getName());
        }
    }

    private function codeFromName(string $name, int $companyId): string
    {
        $base = substr(trim((string) preg_replace('/[^A-Z0-9]+/', '_', strtoupper(Str::ascii($name))), '_'), 0, self::CODE_LENGTH - 3);
        $base = $base === '' ? 'CATEGORY' : $base;

        $code = $base;
        for ($n = 2; $this->repository->existsCode($code, $companyId); $n++) {
            $code = "{$base}_{$n}";
        }

        return $code;
    }
}
