<?php

declare(strict_types=1);

namespace App\Application\Services\Stock;

use App\Core\Entities\ItemEntity;
use App\Core\Entities\SemenProfileEntity;
use App\Core\Entities\SemenSireEntity;
use App\Core\Enums\ItemType;
use App\Core\Enums\SemenType;
use App\Core\Exceptions\SemenException;
use App\Core\Interfaces\IItemCategoryRepository;
use App\Core\Interfaces\IItemRepository;
use App\Core\Interfaces\ISemenProfileRepository;
use App\Core\Interfaces\IUnitOfMeasureRepository;

/**
 * Sire + type → the one semen item (DA2): the existing one, reactivated when inactive, or a new stocked
 * article in straws under the SEMEN category with its profile (SE1, DA8).
 */
final class SemenItemProvisioner
{
    /** @var array<int, array{unit: int, category: int}> */
    private array $catalog = [];

    public function __construct(
        private readonly IItemRepository $items,
        private readonly ISemenProfileRepository $profiles,
        private readonly IUnitOfMeasureRepository $units,
        private readonly IItemCategoryRepository $categories,
    ) {
    }

    public function provide(SemenSireEntity $sire, SemenType $type, int $companyId): ItemEntity
    {
        $profile = $this->profiles->findBySireAndType((int) $sire->id, $type, $companyId);
        if ($profile !== null) {
            $item = $this->items->findById($profile->itemId, $companyId)
                ?? throw new \LogicException("Semen item {$profile->itemId} not found.");
            if (!$item->isActive()) {
                $item->setActive(true);
                $item = $this->items->save($item);
            }

            return $item;
        }

        ['unit' => $unitId, 'category' => $categoryId] = $this->catalog($companyId);
        $breed = $sire->breedName === null ? '' : " {$sire->breedName}";
        $item = $this->items->saveWithGeneratedCode(new ItemEntity(
            null, $companyId, null, "Semen{$breed} · {$sire->name} · {$type->label()}", null,
            ItemType::ARTICLE, true, $unitId, $categoryId,
        ));
        $this->profiles->save(new SemenProfileEntity((int) $item->getId(), $companyId, (int) $sire->id, $type));

        return $item;
    }

    /** @return array{unit: int, category: int} the STRAW unit and the SEMEN root of the company */
    private function catalog(int $companyId): array
    {
        if (isset($this->catalog[$companyId])) {
            return $this->catalog[$companyId];
        }
        $unit = null;
        foreach ($this->units->findAll($companyId, true) as $candidate) {
            if ($candidate->getCode() === 'STRAW') {
                $unit = (int) $candidate->getId();
            }
        }
        $category = null;
        foreach ($this->categories->findAll($companyId, true) as $candidate) {
            if ($candidate->getParentId() === null && $candidate->isSystem() && $candidate->getCode() === SemenStorageRule::SEMEN_CATEGORY_CODE) {
                $category = (int) $candidate->getId();
            }
        }
        if ($unit === null || $category === null) {
            throw SemenException::on('domain', 'Falta la unidad Pajuela o la categoría Semen en esta empresa.');
        }

        return $this->catalog[$companyId] = ['unit' => $unit, 'category' => $category];
    }
}
