<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Entities\AnimalCategoryEntity;
use App\Core\Entities\AnimalSubcategoryEntity;

/**
 * Turns what was written in a C/S cell into a (category, subcategory) pair of the catalog.
 *
 * The cell accepts a category ("Novillito"), a subcategory ("Reposición") or both
 * ("Vaquillona / Reposición"). A subcategory alone is enough, because it belongs to exactly one
 * category. What the resolver never does is guess: text that fits two options compatible with
 * the animal's sex comes back AMBIGUOUS, and a person picks.
 *
 * The scanner transcribes, this resolves. Keeping the rule here, and only here, is what lets the
 * review window, the order screen and the tests agree on what a cell means.
 *
 * Pure: it works on the catalog it is handed and knows nothing about persistence.
 */
final class AnimalCategoryTextResolver
{
    /** Shorter prefixes match half the catalog and say nothing. */
    private const MIN_PREFIX_LENGTH = 3;

    /**
     * @var list<array{category: AnimalCategoryEntity, subcategory: ?AnimalSubcategoryEntity, label: string, strings: list<string>}>
     */
    private array $options = [];

    /**
     * @param AnimalCategoryEntity[] $categories the catalog, subcategories loaded
     */
    public function __construct(array $categories)
    {
        foreach ($categories as $category) {
            $categoryStrings = array_values(array_unique([
                self::normalize($category->getName()),
                self::normalize($category->getCode()),
                self::normalize(self::firstWord($category->getName())),
            ]));

            $this->options[] = [
                'category' => $category,
                'subcategory' => null,
                'label' => self::label($category, null),
                'strings' => $categoryStrings,
            ];

            foreach ($category->getSubcategories() as $subcategory) {
                $short = self::shortSubcategoryName($category, $subcategory);
                $subStrings = [
                    self::normalize($subcategory->getName()),
                    self::normalize($subcategory->getCode()),
                    self::normalize($short),
                ];

                foreach ($categoryStrings as $prefix) {
                    $subStrings[] = $prefix . '/' . self::normalize($short);
                    $subStrings[] = $prefix . '/' . self::normalize($subcategory->getName());
                    $subStrings[] = $prefix . '/' . self::normalize($subcategory->getCode());
                }

                $this->options[] = [
                    'category' => $category,
                    'subcategory' => $subcategory,
                    'label' => self::label($category, $subcategory),
                    'strings' => array_values(array_unique($subStrings)),
                ];
            }
        }
    }

    /**
     * The canonical way a pair is written: "Novillito", "Vaquillona / Reposición". It is what the
     * sheet prints and what the review selector sends back, so it always resolves to itself.
     */
    public static function label(AnimalCategoryEntity $category, ?AnimalSubcategoryEntity $subcategory): string
    {
        return $subcategory === null
            ? $category->getName()
            : $category->getName() . ' / ' . self::shortSubcategoryName($category, $subcategory);
    }

    /**
     * Blank, or one of the marks a printed sheet uses for "nothing here".
     */
    public static function isBlank(?string $text): bool
    {
        $value = trim((string) $text);

        return $value === '' || in_array($value, ['-', '—', '–', '--', '/'], true);
    }

    /**
     * @param string $sex 'M' or 'H': options of the other sex are never candidates
     */
    public function resolve(string $text, string $sex): AnimalCategoryResolution
    {
        $normalized = self::normalize($text);

        if ($normalized === '') {
            return AnimalCategoryResolution::notFound();
        }

        $matches = $this->exact($normalized);

        if ($matches === [] && str_contains($normalized, '/')) {
            $matches = $this->compound($normalized);
        }

        if ($matches === []) {
            $matches = $this->prefix($normalized);
        }

        return $this->decide($this->preferCategory($matches), $sex);
    }

    /**
     * The same checks for a pair chosen from a list instead of written: it exists, the
     * subcategory belongs to the category, and the category fits the animal.
     */
    public function resolveIds(int $categoryId, ?int $subcategoryId, string $sex): AnimalCategoryResolution
    {
        foreach ($this->options as $option) {
            if ((int) $option['category']->getId() !== $categoryId) {
                continue;
            }

            $optionSubId = $option['subcategory']?->getId();

            if (($subcategoryId === null && $optionSubId === null) || ($subcategoryId !== null && (int) $optionSubId === $subcategoryId)) {
                return $this->decide([$option], $sex);
            }
        }

        return AnimalCategoryResolution::notFound();
    }

    /**
     * @return list<array{category: AnimalCategoryEntity, subcategory: ?AnimalSubcategoryEntity, label: string, strings: list<string>}>
     */
    private function exact(string $normalized): array
    {
        return array_values(array_filter(
            $this->options,
            fn (array $option) => in_array($normalized, $option['strings'], true)
        ));
    }

    /**
     * "Vaquillona / Desc": the left side names the category, the right side narrows it to one of
     * its subcategories. Either side may be abbreviated.
     *
     * @return list<array{category: AnimalCategoryEntity, subcategory: ?AnimalSubcategoryEntity, label: string, strings: list<string>}>
     */
    private function compound(string $normalized): array
    {
        [$left, $right] = array_map('trim', explode('/', $normalized, 2));

        if ($left === '' || $right === '') {
            return [];
        }

        $categoryIds = [];
        foreach ($this->options as $option) {
            if ($option['subcategory'] === null && $this->matchesLoosely($left, $option['strings'])) {
                $categoryIds[(int) $option['category']->getId()] = true;
            }
        }

        return array_values(array_filter(
            $this->options,
            function (array $option) use ($categoryIds, $right): bool {
                $subcategory = $option['subcategory'];

                if ($subcategory === null || !isset($categoryIds[(int) $option['category']->getId()])) {
                    return false;
                }

                return $this->matchesLoosely($right, [
                    self::normalize($subcategory->getName()),
                    self::normalize($subcategory->getCode()),
                    self::normalize(self::shortSubcategoryName($option['category'], $subcategory)),
                ]);
            }
        ));
    }

    /**
     * @return list<array{category: AnimalCategoryEntity, subcategory: ?AnimalSubcategoryEntity, label: string, strings: list<string>}>
     */
    private function prefix(string $normalized): array
    {
        if (mb_strlen($normalized) < self::MIN_PREFIX_LENGTH) {
            return [];
        }

        return array_values(array_filter(
            $this->options,
            fn (array $option) => $this->matchesLoosely($normalized, $option['strings'])
        ));
    }

    /**
     * Exact, prefix of the whole string, or prefix of one of its words ("CUT" in "VACA CUT/DESCARTE").
     *
     * @param list<string> $strings
     */
    private function matchesLoosely(string $needle, array $strings): bool
    {
        foreach ($strings as $string) {
            if ($string === $needle || str_starts_with($string, $needle)) {
                return true;
            }

            if (mb_strlen($needle) < self::MIN_PREFIX_LENGTH) {
                continue;
            }

            foreach (preg_split('/[\s\/]+/u', $string) ?: [] as $word) {
                if ($word !== '' && str_starts_with($word, $needle)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Writing a category's name means the category. "Vaquillona" also starts every one of its
     * subcategories' names, and must not turn into a question about which of them.
     *
     * @param list<array{category: AnimalCategoryEntity, subcategory: ?AnimalSubcategoryEntity, label: string, strings: list<string>}> $matches
     * @return list<array{category: AnimalCategoryEntity, subcategory: ?AnimalSubcategoryEntity, label: string, strings: list<string>}>
     */
    private function preferCategory(array $matches): array
    {
        $categoryIds = [];
        foreach ($matches as $option) {
            if ($option['subcategory'] === null) {
                $categoryIds[(int) $option['category']->getId()] = true;
            }
        }

        return array_values(array_filter(
            $matches,
            fn (array $option) => $option['subcategory'] === null || !isset($categoryIds[(int) $option['category']->getId()])
        ));
    }

    /**
     * @param list<array{category: AnimalCategoryEntity, subcategory: ?AnimalSubcategoryEntity, label: string, strings: list<string>}> $matches
     */
    private function decide(array $matches, string $sex): AnimalCategoryResolution
    {
        if ($matches === []) {
            return AnimalCategoryResolution::notFound();
        }

        $compatible = array_values(array_filter(
            $matches,
            fn (array $option) => in_array($option['category']->getSex(), ['BOTH', $sex], true)
        ));

        if ($compatible === []) {
            return AnimalCategoryResolution::sexMismatch(array_column($matches, 'label'));
        }

        if (count($compatible) > 1) {
            return AnimalCategoryResolution::ambiguous(array_column($compatible, 'label'));
        }

        return AnimalCategoryResolution::resolved($compatible[0]['category'], $compatible[0]['subcategory']);
    }

    /**
     * "Vaquillona de Reposición" is written "Reposición" under the Vaquillona category: the
     * category's first word, and a "de" after it, add nothing once the category is known.
     */
    private static function shortSubcategoryName(AnimalCategoryEntity $category, AnimalSubcategoryEntity $subcategory): string
    {
        $name = trim($subcategory->getName());
        $prefix = self::firstWord($category->getName());

        if ($prefix !== '' && mb_stripos($name, $prefix . ' ') === 0) {
            $name = trim(mb_substr($name, mb_strlen($prefix)));

            if (mb_stripos($name, 'de ') === 0) {
                $name = trim(mb_substr($name, 3));
            }
        }

        return $name !== '' ? $name : $subcategory->getName();
    }

    private static function firstWord(string $value): string
    {
        return (string) (preg_split('/\s+/u', trim($value))[0] ?? '');
    }

    private static function normalize(string $value): string
    {
        $value = strtr($value, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
            '_' => ' ', '.' => ' ', '|' => '/',
        ]);
        $value = mb_strtoupper(trim($value));
        $value = (string) preg_replace('/\s*\/\s*/u', '/', $value);

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }
}
