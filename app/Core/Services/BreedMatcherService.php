<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Entities\BreedEntity;

/**
 * Pure PHP Domain Service responsible for matching raw string inputs
 * to existing Breed entities using string similarity algorithms.
 */
final class BreedMatcherService
{
    /**
     * Maximum Levenshtein distance to consider a match valid.
     * e.g., 2 allows "brangu" -> "brangus" (1 change),
     * "bread" -> "breed" (2 changes), but rejects "braford" -> "brangus" (4 changes).
     */
    private const MAX_DISTANCE = 2;

    /**
     * Find the best matching BreedEntity from a list of available breeds.
     * Returns null if no match is found within the acceptable distance.
     *
     * @param string $rawInput
     * @param array<BreedEntity> $availableBreeds
     * @return BreedEntity|null
     */
    public function findBestMatch(string $rawInput, array $availableBreeds): ?BreedEntity
    {
        $normalizedInput = mb_strtolower(trim($rawInput));

        if ($normalizedInput === '') {
            return null;
        }

        // Pass 1: Exact Match (Case-insensitive)
        foreach ($availableBreeds as $breed) {
            if (mb_strtolower($breed->getName()) === $normalizedInput) {
                return $breed;
            }
        }

        // Pass 2: Fuzzy Match (Levenshtein Distance)
        $bestMatch = null;
        $lowestDistance = self::MAX_DISTANCE + 1;

        foreach ($availableBreeds as $breed) {
            $normalizedBreedName = mb_strtolower($breed->getName());
            
            // Levenshtein function requires strings <= 255 chars, standard PHP constraint.
            if (strlen($normalizedInput) > 255 || strlen($normalizedBreedName) > 255) {
                continue;
            }

            $distance = levenshtein($normalizedInput, $normalizedBreedName);

            if ($distance < $lowestDistance) {
                $lowestDistance = $distance;
                $bestMatch = $breed;
            }
        }

        if ($lowestDistance <= self::MAX_DISTANCE) {
            return $bestMatch;
        }

        // No match found within acceptable tolerance
        return null;
    }
}
