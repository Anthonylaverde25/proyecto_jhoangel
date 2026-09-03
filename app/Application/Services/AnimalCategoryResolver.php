<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Models\AnimalCategory;
use App\Models\AnimalSubcategory;
use Illuminate\Support\Collection;

final class CategoryResolutionResult
{
    public function __construct(
        public readonly ?int $categoryId,
        public readonly ?int $subcategoryId,
        public readonly ?string $sex,
        public readonly ?string $categoryCode,
        public readonly ?string $subcategoryCode,
        public readonly bool $isResolved,
        public readonly bool $requiresReview,
        public readonly float $confidence
    ) {
    }
}

final class AnimalCategoryResolver
{
    /**
     * @var Collection<int, AnimalCategory>|null
     */
    private ?Collection $cachedCategories = null;

    /**
     * Resolve handwritten category text into concrete category and subcategory IDs.
     * Implements a Parent-First deterministic resolution policy without arbitrary guessing.
     *
     * @param string|null $rawCategory
     * @param string|null $rawSex
     * @return CategoryResolutionResult
     */
    public function resolve(?string $rawCategory, ?string $rawSex = null): CategoryResolutionResult
    {
        $normalizedSex = $this->normalizeSexInput($rawSex);

        if ($rawCategory === null || trim($rawCategory) === '') {
            return new CategoryResolutionResult(
                categoryId: null,
                subcategoryId: null,
                sex: $normalizedSex,
                categoryCode: null,
                subcategoryCode: null,
                isResolved: false,
                requiresReview: true,
                confidence: 0.0
            );
        }

        $categories = $this->getCategories();
        $cleanText = $this->sanitizeText($rawCategory);

        // 1. Detect Base/Parent Category
        $matchedCategory = null;
        $inferredSex = null;

        // Check specific feminine/masculine variations first
        if ($this->containsToken($cleanText, ['TERNERA'])) {
            $matchedCategory = $categories->firstWhere('code', 'TERNERO');
            $inferredSex = 'H';
        } elseif ($this->containsToken($cleanText, ['TERNERO', 'TERNEROS', 'TER'])) {
            $matchedCategory = $categories->firstWhere('code', 'TERNERO');
            $inferredSex = 'M';
        } elseif ($this->containsToken($cleanText, ['TORITO', 'TORITOS'])) {
            $matchedCategory = $categories->firstWhere('code', 'TORITO');
            $inferredSex = 'M';
        } elseif ($this->containsToken($cleanText, ['TORO', 'TOROS', 'REPRODUCTOR', 'PADRILLO', 'REPRODUCTORES'])) {
            $matchedCategory = $categories->firstWhere('code', 'TORO');
            $inferredSex = 'M';
        } elseif ($this->containsToken($cleanText, ['NOVILLITO', 'NOVILLITOS'])) {
            $matchedCategory = $categories->firstWhere('code', 'NOVILLITO');
            $inferredSex = 'M';
        } elseif ($this->containsToken($cleanText, ['NOVILLO', 'NOVILLOS', 'NOV'])) {
            $matchedCategory = $categories->firstWhere('code', 'NOVILLO');
            $inferredSex = 'M';
        } elseif ($this->containsToken($cleanText, ['VAQUILLONA', 'VAQUILLONAS', 'VAQ', 'VAQUILLITA'])) {
            $matchedCategory = $categories->firstWhere('code', 'VAQUILLONA');
            $inferredSex = 'H';
        } elseif ($this->containsToken($cleanText, ['VACA', 'VACAS', 'VCA'])) {
            $matchedCategory = $categories->firstWhere('code', 'VACA');
            $inferredSex = 'H';
        }

        // 2. If Parent Category was NOT found, do not hallucinate a parent for orphaned qualifiers
        if (!$matchedCategory) {
            return new CategoryResolutionResult(
                categoryId: null,
                subcategoryId: null,
                sex: $normalizedSex,
                categoryCode: null,
                subcategoryCode: null,
                isResolved: false,
                requiresReview: true,
                confidence: 0.0
            );
        }

        // 3. Resolve Child Subcategory strictly within the parent category's subcategories
        $matchedSubcategory = null;
        $subcategories = $matchedCategory->subcategories ?? collect();

        foreach ($subcategories as $sub) {
            $subCode = strtoupper($sub->code);
            $subName = $this->sanitizeText($sub->name);

            if ($this->containsToken($cleanText, [$subCode, $subName])) {
                $matchedSubcategory = $sub;
                break;
            }

            // Aliases per subcategory
            if ($subCode === 'DESCARTE_CUT' && $this->containsToken($cleanText, ['CUT', 'DESCARTE', 'REFUGO'])) {
                $matchedSubcategory = $sub;
                break;
            }

            if ($subCode === 'REPOSICION' && $this->containsToken($cleanText, ['REP', 'REPOSICION', 'REEMPLAZO'])) {
                $matchedSubcategory = $sub;
                break;
            }

            if ($subCode === 'DESCARTE_FAENA' && $this->containsToken($cleanText, ['FAENA', 'CARNICERIA', 'GORDURA'])) {
                $matchedSubcategory = $sub;
                break;
            }

            if ($subCode === 'PLANTEL' && $this->containsToken($cleanText, ['PLANTEL', 'CABANA', 'PEDIGREE', 'PURAS'])) {
                $matchedSubcategory = $sub;
                break;
            }
        }

        // If no explicit subcategory was detected for Vaca, assign default general subcategory if present
        if (!$matchedSubcategory && $matchedCategory->code === 'VACA') {
            $matchedSubcategory = $subcategories->firstWhere('code', 'RODEO_GENERAL');
        }

        $finalSex = $normalizedSex ?? $inferredSex;

        return new CategoryResolutionResult(
            categoryId: $matchedCategory->id,
            subcategoryId: $matchedSubcategory?->id,
            sex: $finalSex,
            categoryCode: $matchedCategory->code,
            subcategoryCode: $matchedSubcategory?->code,
            isResolved: true,
            requiresReview: false,
            confidence: $matchedSubcategory ? 1.0 : 0.95
        );
    }

    /**
     * Sanitize input text by uppercasing and stripping accents.
     */
    private function sanitizeText(string $text): string
    {
        $text = mb_strtoupper(trim($text));
        $unwanted = [
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U',
            'Ü' => 'U', 'Ñ' => 'N',
        ];
        return strtr($text, $unwanted);
    }

    /**
     * Check if clean text contains any of the target tokens.
     *
     * @param string $text
     * @param string[] $tokens
     * @return bool
     */
    private function containsToken(string $text, array $tokens): bool
    {
        foreach ($tokens as $token) {
            $cleanToken = $this->sanitizeText($token);
            if ($cleanToken === '') {
                continue;
            }

            // Word boundary match
            if (preg_match('/\b' . preg_quote($cleanToken, '/') . '\b/u', $text)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Normalize explicit sex inputs ('M', 'H', 'F', 'MACHO', 'HEMBRA').
     */
    private function normalizeSexInput(?string $sex): ?string
    {
        if ($sex === null) {
            return null;
        }

        $clean = $this->sanitizeText($sex);
        if (in_array($clean, ['M', 'MACHO', 'MALE', '1'])) {
            return 'M';
        }
        if (in_array($clean, ['H', 'F', 'HEMBRA', 'FEMALE', '0'])) {
            return 'H';
        }

        return null;
    }

    /**
     * Retrieve all categories with preloaded subcategories.
     *
     * @return Collection<int, AnimalCategory>
     */
    private function getCategories(): Collection
    {
        if ($this->cachedCategories === null) {
            $this->cachedCategories = AnimalCategory::with('subcategories')->get();
        }

        return $this->cachedCategories;
    }
}
