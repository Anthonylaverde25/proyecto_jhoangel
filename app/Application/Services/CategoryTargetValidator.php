<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Core\Interfaces\IAnimalCategoryRepository;
use App\Core\Services\AnimalCategoryTextResolver;

/**
 * The new category an order declares for each animal, checked against the catalog and against
 * the sex the caravan has in the system. Shared by transfer orders and weaning orders, so both
 * refuse the same pairs.
 */
final class CategoryTargetValidator
{
    public function __construct(private readonly IAnimalCategoryRepository $categoryRepository)
    {
    }

    /**
     * @param list<array{caravan_id: int, target_category_id?: ?int, target_subcategory_id?: ?int}> $requested
     *        only the animals that asked for a category
     * @param array<int, string> $sexByCaravanId
     * @return array{targets: array<int, array{0: int, 1: ?int}>, invalid: int} caravan id => [category id, subcategory id]
     */
    public function resolve(array $requested, array $sexByCaravanId): array
    {
        if ($requested === []) {
            return ['targets' => [], 'invalid' => 0];
        }

        $resolver = new AnimalCategoryTextResolver($this->categoryRepository->all());
        $targets = [];
        $invalid = 0;

        foreach ($requested as $animal) {
            $categoryId = $animal['target_category_id'] ?? null;
            $sex = $sexByCaravanId[$animal['caravan_id']] ?? null;

            $resolution = $categoryId !== null && $sex !== null
                ? $resolver->resolveIds($categoryId, $animal['target_subcategory_id'] ?? null, $sex)
                : null;

            if ($resolution === null || !$resolution->isResolved()) {
                $invalid++;
                continue;
            }

            $targets[$animal['caravan_id']] = [(int) $resolution->category?->getId(), $resolution->subcategory?->getId()];
        }

        return ['targets' => $targets, 'invalid' => $invalid];
    }
}
