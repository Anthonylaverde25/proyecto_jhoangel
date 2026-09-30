<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Entities\AnimalCategoryEntity;
use App\Core\Entities\AnimalSubcategoryEntity;

/**
 * What a C/S text (or a pair of ids) turned out to be against the catalog.
 *
 * Only RESOLVED carries a category. The other three are answers the review window has to show
 * next to the cell, so they say which options were close.
 */
final readonly class AnimalCategoryResolution
{
    public const RESOLVED = 'RESOLVED';
    public const AMBIGUOUS = 'AMBIGUOUS';
    public const NOT_FOUND = 'NOT_FOUND';
    public const SEX_MISMATCH = 'SEX_MISMATCH';

    /**
     * @param list<string> $candidates C/S labels of the options that matched
     */
    private function __construct(
        public string $status,
        public ?AnimalCategoryEntity $category = null,
        public ?AnimalSubcategoryEntity $subcategory = null,
        public array $candidates = []
    ) {
    }

    public static function resolved(AnimalCategoryEntity $category, ?AnimalSubcategoryEntity $subcategory): self
    {
        return new self(self::RESOLVED, $category, $subcategory);
    }

    /**
     * @param list<string> $candidates
     */
    public static function ambiguous(array $candidates): self
    {
        return new self(self::AMBIGUOUS, candidates: $candidates);
    }

    public static function notFound(): self
    {
        return new self(self::NOT_FOUND);
    }

    /**
     * @param list<string> $candidates
     */
    public static function sexMismatch(array $candidates): self
    {
        return new self(self::SEX_MISMATCH, candidates: $candidates);
    }

    public function isResolved(): bool
    {
        return $this->status === self::RESOLVED;
    }
}
