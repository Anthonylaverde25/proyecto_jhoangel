<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Models\Breed;
use App\Models\Color;

/**
 * Result DTO for phenotype (breed and coat color) resolution.
 */
final class PhenotypeResolutionResult
{
    public function __construct(
        public readonly ?int $breedId,
        public readonly ?string $breedName,
        public readonly ?int $colorId,
        public readonly ?string $colorName,
        public readonly float $confidence,
        public readonly bool $isResolved
    ) {
    }
}

/**
 * Deterministic application service to resolve raw OCR/text cells
 * into canonical Breed ID and Color ID.
 */
class BreedAndColorResolver
{
    /**
     * Common compound phrases and aliases mapped to standard breed and color names.
     */
    private const KNOWN_COMBINATIONS = [
        'angus negro'        => ['breed' => 'Angus', 'color' => 'Negro'],
        'angus colorado'     => ['breed' => 'Angus', 'color' => 'Colorado'],
        'black angus'        => ['breed' => 'Angus', 'color' => 'Negro'],
        'red angus'          => ['breed' => 'Angus', 'color' => 'Colorado'],
        'angus rojo'         => ['breed' => 'Angus', 'color' => 'Colorado'],
        'brangus negro'      => ['breed' => 'Brangus', 'color' => 'Negro'],
        'brangus colorado'   => ['breed' => 'Brangus', 'color' => 'Colorado'],
        'brangus col.'       => ['breed' => 'Brangus', 'color' => 'Colorado'],
        'brangus col'        => ['breed' => 'Brangus', 'color' => 'Colorado'],
        'brangus rojo'       => ['breed' => 'Brangus', 'color' => 'Colorado'],
        'hereford pampa'     => ['breed' => 'Hereford', 'color' => 'Pampa'],
        'polled hereford'    => ['breed' => 'Hereford', 'color' => 'Pampa'],
        'braford colorado'   => ['breed' => 'Braford', 'color' => 'Colorado'],
        'braford pampa'      => ['breed' => 'Braford', 'color' => 'Pampa'],
        'holando overo negro'=> ['breed' => 'Holando', 'color' => 'Overo Negro'],
        'holando overo col'  => ['breed' => 'Holando', 'color' => 'Overo Colorado'],
        'cruza careta'       => ['breed' => 'Cruza', 'color' => 'Pampa'],
        'careta'             => ['breed' => 'Cruza', 'color' => 'Pampa'],
        'careto'             => ['breed' => 'Cruza', 'color' => 'Pampa'],
        'cruza britanica'    => ['breed' => 'Cruza', 'color' => null],
        'cruza cebu'         => ['breed' => 'Cruza', 'color' => null],
        'shorthorn rosillo'  => ['breed' => 'Shorthorn', 'color' => 'Rosillo'],
        'shorthorn colorado' => ['breed' => 'Shorthorn', 'color' => 'Colorado'],
        'shorthorn blanco'   => ['breed' => 'Shorthorn', 'color' => 'Blanco'],
        'limousin colorado'  => ['breed' => 'Limousin', 'color' => 'Colorado'],
        'limousin negro'     => ['breed' => 'Limousin', 'color' => 'Negro'],
    ];

    /**
     * Color aliases.
     */
    private const COLOR_ALIASES = [
        'overo negro'     => 'Overo Negro',
        'overo colorado'  => 'Overo Colorado',
        'overo col'       => 'Overo Colorado',
        'overo rojo'      => 'Overo Colorado',
        'overo'           => 'Overo Negro',
        'pampa'           => 'Pampa',
        'cara blanca'     => 'Pampa',
        'negro'           => 'Negro',
        'negra'           => 'Negro',
        'black'           => 'Negro',
        'colorado'        => 'Colorado',
        'colorada'        => 'Colorado',
        'col.'            => 'Colorado',
        'col'             => 'Colorado',
        'rojo'            => 'Colorado',
        'red'             => 'Colorado',
        'rosillo'         => 'Rosillo',
        'roano'           => 'Rosillo',
        'bayo'            => 'Bayo',
        'baya'            => 'Bayo',
        'blanco'          => 'Blanco',
        'blanca'          => 'Blanco',
        'white'           => 'Blanco',
        'barcino'         => 'Barcino',
        'atigrado'        => 'Barcino',
    ];

    /**
     * Resolve raw input string to Breed and Color IDs.
     */
    public function resolve(?string $rawInput): PhenotypeResolutionResult
    {
        if ($rawInput === null) {
            return new PhenotypeResolutionResult(null, null, null, null, 0.0, false);
        }

        $normalized = mb_strtolower(trim($rawInput));
        if ($normalized === '') {
            return new PhenotypeResolutionResult(null, null, null, null, 0.0, false);
        }

        // 1. Check direct match in known compound combinations (using word boundaries)
        foreach (self::KNOWN_COMBINATIONS as $combo => $target) {
            if ($normalized === $combo || preg_match('/\b' . preg_quote($combo, '/') . '\b/u', $normalized)) {
                return $this->resolveByNamePair($target['breed'], $target['color'], 1.0);
            }
        }

        // 2. Fetch master catalogs
        $allBreeds = Breed::all();
        $allColors = Color::all();

        $resolvedBreed = null;
        $resolvedColor = null;
        $confidence = 0.5;

        // 3. Extract Color from tokens
        foreach (self::COLOR_ALIASES as $alias => $canonicalColorName) {
            if (preg_match('/\b' . preg_quote($alias, '/') . '\b/u', $normalized)) {
                $resolvedColor = $allColors->first(fn ($c) => mb_strtolower($c->name) === mb_strtolower($canonicalColorName));
                if ($resolvedColor) {
                    $normalized = trim(preg_replace('/\b' . preg_quote($alias, '/') . '\b/u', '', $normalized) ?? '');
                    break;
                }
            }
        }

        // 4. Match Breed with remaining string
        $cleanString = trim(preg_replace('/[^a-z0-9]/u', '', $normalized) ?? '');

        if ($cleanString !== '') {
            // 4a. Exact matching first
            foreach ($allBreeds as $breed) {
                $cleanBreed = mb_strtolower(trim($breed->name));
                if ($cleanString === $cleanBreed) {
                    $resolvedBreed = $breed;
                    $confidence = 1.0;
                    break;
                }
            }

            // 4b. Word/prefix matching (longer breed names first to avoid 'angus' matching 'brangus')
            if (!$resolvedBreed) {
                $sortedBreeds = $allBreeds->sortByDesc(fn ($b) => strlen($b->name));
                foreach ($sortedBreeds as $breed) {
                    $cleanBreed = mb_strtolower(trim($breed->name));
                    if (str_starts_with($cleanString, $cleanBreed) || str_ends_with($cleanString, $cleanBreed)) {
                        $resolvedBreed = $breed;
                        $confidence = 0.95;
                        break;
                    }
                }
            }

            // 4c. Fuzzy matching with Levenshtein (distance <= 2)
            if (!$resolvedBreed) {
                foreach ($allBreeds as $breed) {
                    $cleanBreed = mb_strtolower(trim($breed->name));
                    $dist = levenshtein($cleanString, $cleanBreed);
                    if ($dist <= 2) {
                        $resolvedBreed = $breed;
                        $confidence = 0.8;
                        break;
                    }
                }
            }
        }

        // 5. Default breed if specific color strongly implies breed
        if (!$resolvedBreed && $resolvedColor) {
            if ($resolvedColor->name === 'Pampa') {
                $resolvedBreed = $allBreeds->firstWhere('name', 'Hereford');
            }
        }

        return new PhenotypeResolutionResult(
            breedId: $resolvedBreed?->id,
            breedName: $resolvedBreed?->name,
            colorId: $resolvedColor?->id,
            colorName: $resolvedColor?->name,
            confidence: ($resolvedBreed !== null) ? $confidence : 0.0,
            isResolved: $resolvedBreed !== null || $resolvedColor !== null
        );
    }

    /**
     * Resolve by exact target names.
     */
    private function resolveByNamePair(?string $breedName, ?string $colorName, float $confidence): PhenotypeResolutionResult
    {
        $breed = $breedName ? Breed::where('name', $breedName)->first() : null;
        $color = $colorName ? Color::where('name', $colorName)->first() : null;

        return new PhenotypeResolutionResult(
            breedId: $breed?->id,
            breedName: $breed?->name,
            colorId: $color?->id,
            colorName: $color?->name,
            confidence: $confidence,
            isResolved: $breed !== null || $color !== null
        );
    }
}
