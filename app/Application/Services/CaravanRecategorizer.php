<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Core\Entities\CaravanEntity;
use App\Core\Enums\CastrationStatus;
use App\Core\Enums\CategoryChangeCause;
use App\Core\Enums\CategoryChangeSource;
use App\Core\Exceptions\DomainException;
use App\Core\Interfaces\IAnimalCategoryRepository;
use App\Core\Interfaces\ICaravanCastrationRepository;
use App\Core\Interfaces\ICaravanRepository;
use App\Core\Interfaces\ICategoryChangeRepository;
use App\Core\Services\CastrationPolicy;

final class CaravanRecategorizer
{
    public function __construct(
        private readonly ICaravanRepository $caravanRepository,
        private readonly IAnimalCategoryRepository $categoryRepository,
        private readonly ICategoryChangeRepository $categoryChangeRepository,
        private readonly ICaravanCastrationRepository $castrationRepository,
        private readonly CastrationPolicy $castrationPolicy = new CastrationPolicy()
    ) {
    }

    /**
     * Moves the caravan to the target category (and subcategory), saves it and records the
     * event. A subcategory of another category is refused (H1); a castrated animal is refused
     * TORITO / TORO (CS9). No-op when nothing changes.
     */
    public function change(
        CaravanEntity $caravan,
        int $toCategoryId,
        ?int $toSubcategoryId,
        CategoryChangeCause $cause,
        string $occurredOn,
        ?CategoryChangeSource $source,
        ?int $sourceId,
        ?int $userId
    ): void {
        if ($caravan->getCategoryId() === $toCategoryId && $caravan->getSubcategoryId() === $toSubcategoryId) {
            return;
        }

        $toCategory = $this->categoryRepository->findById($toCategoryId);
        if ($toCategory === null) {
            throw new DomainException("La categoría destino ID {$toCategoryId} no existe.");
        }

        $status = $caravan->getCastrationStatus();
        if ($cause !== CategoryChangeCause::CORRECTION && !$this->castrationPolicy->allowsCategory($status, $toCategory->getCode())) {
            throw new DomainException("{$caravan->label()} está castrado: no puede pasar a {$toCategory->getName()}.");
        }

        $toSubcategoryName = null;
        if ($toSubcategoryId !== null) {
            $validSubcategories = $this->categoryRepository->getSubcategoriesByCategoryId($toCategoryId);
            $foundSubcategory = null;
            foreach ($validSubcategories as $sub) {
                if ($sub->getId() === $toSubcategoryId) {
                    $foundSubcategory = $sub;
                    break;
                }
            }

            if ($foundSubcategory === null) {
                throw new DomainException("La subcategoría ID {$toSubcategoryId} no pertenece a la categoría {$toCategory->getName()}.");
            }

            $toSubcategoryName = $foundSubcategory->getName();
        }

        $fromCategoryId = $caravan->getCategoryId();
        $fromSubcategoryId = $caravan->getSubcategoryId();

        $caravan->setCategoryId($toCategoryId);
        $caravan->setCategoryCode($toCategory->getCode());
        $caravan->setCategoryName($toCategory->getName());
        $caravan->setSubcategoryId($toSubcategoryId);
        $caravan->setSubcategoryName($toSubcategoryName);

        $this->caravanRepository->save($caravan);

        $companyId = $caravan->getCompanyId() ?? 0;
        $caravanId = (int) $caravan->getId();

        $this->categoryChangeRepository->record(
            companyId: $companyId,
            caravanId: $caravanId,
            fromCategoryId: $fromCategoryId,
            fromSubcategoryId: $fromSubcategoryId,
            toCategoryId: $toCategoryId,
            toSubcategoryId: $toSubcategoryId,
            cause: $cause,
            occurredOn: $occurredOn,
            sourceType: $source,
            sourceId: $sourceId,
            userId: $userId
        );
    }

    /**
     * The guard of the paths that still write the category on their own (DC7).
     *
     * @param array<int, int> $categoryIdByCaravanId caravanId => targetCategoryId
     * @return array<int, string> caravan id => message, for those refused
     */
    public function refusals(array $categoryIdByCaravanId): array
    {
        if ($categoryIdByCaravanId === []) {
            return [];
        }

        // Cache category entities
        $allCategories = $this->categoryRepository->all();
        $categoriesById = [];
        foreach ($allCategories as $cat) {
            if ($cat->getId() !== null) {
                $categoriesById[$cat->getId()] = $cat;
            }
        }

        // Filter those caravans whose target is TORITO or TORO
        $caravansToCheck = [];
        foreach ($categoryIdByCaravanId as $caravanId => $targetCatId) {
            $targetCat = $categoriesById[$targetCatId] ?? null;
            if ($targetCat !== null && in_array(strtoupper($targetCat->getCode()), ['TORITO', 'TORO'], true)) {
                $caravansToCheck[$caravanId] = $targetCat;
            }
        }

        if ($caravansToCheck === []) {
            return [];
        }

        $activeCastrations = $this->castrationRepository->activeByCaravans(array_keys($caravansToCheck));

        $refusals = [];
        foreach ($caravansToCheck as $caravanId => $targetCat) {
            $caravan = $this->caravanRepository->findById($caravanId);
            if ($caravan === null) {
                continue;
            }

            $hasActiveCastration = isset($activeCastrations[$caravanId]);
            $status = $this->castrationPolicy->status(
                $caravan->getSex(),
                $caravan->getCategoryCode(),
                $hasActiveCastration
            );

            if (!$this->castrationPolicy->allowsCategory($status, $targetCat->getCode())) {
                $refusals[$caravanId] = "{$caravan->label()} está castrado: no puede pasar a {$targetCat->getName()}.";
            }
        }

        return $refusals;
    }
}
