<?php

declare(strict_types=1);

namespace App\Core\Services;

/**
 * Keeps the category tree acyclic (C3): a category cannot hang from itself or from one of its
 * descendants.
 */
final class CategoryTreeGuard
{
    /**
     * @param array<int, ?int> $parentMap id => parent_id of every category of the company
     */
    public static function createsCycle(array $parentMap, int $categoryId, ?int $newParentId): bool
    {
        $cursor = $newParentId;
        $guard = count($parentMap) + 1;

        while ($cursor !== null && $guard-- > 0) {
            if ($cursor === $categoryId) {
                return true;
            }
            $cursor = $parentMap[$cursor] ?? null;
        }

        return false;
    }
}
