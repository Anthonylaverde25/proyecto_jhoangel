<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Entities\BreedEntity;
use App\Core\Entities\EntryOrderBreedEntity;
use App\Core\Entities\EntryOrderCategoryEntity;
use App\Core\Enums\AnimalSex;
use App\Core\ValueObjects\EntryTroop;
use App\Core\ValueObjects\TroopLineMatch;

/**
 * Turns the breed, coat and category written on a reception line ("Braford", "Col.", "Novillito")
 * into the order's line they name. The scanner transcribes, this resolves — so the review, the
 * manual reception and the tests agree on what a cell means.
 *
 * A word fits a name when it is the name, its catalog code, a prefix of three letters or more, or
 * the same word in the other gender ("Colorada" for "Colorado"). Exact fits win over the rest. It
 * never guesses beyond that: text that fits two lines comes back AMBIGUOUS with the candidates,
 * for a person to pick, and text that fits none comes back UNKNOWN.
 *
 * A coat left blank is certain when the order has one line of that breed, the same way a category
 * follows from the sex. A breed of the catalog the order does not declare is OUTSIDE_ORDER: the
 * animal arrived, and the difference with the purchase is reported, not hidden.
 *
 * Pure: it works on the order and the catalog it is handed.
 */
final class TroopLineResolver
{
    /** Shorter prefixes match half the catalog and say nothing. */
    private const MIN_PREFIX_LENGTH = 3;

    /** @var array<int, string> coat id → its catalog code */
    private array $colorCodes = [];

    /**
     * @param BreedEntity[] $catalog every breed, with the coats it admits
     */
    public function __construct(private readonly array $catalog = [])
    {
        foreach ($catalog as $breed) {
            foreach ($breed->getColors() as $color) {
                if ($color['code'] !== null) {
                    $this->colorCodes[$color['id']] = $color['code'];
                }
            }
        }
    }

    /**
     * The breed line a written breed and coat point to. Breed and coat may come together in the
     * breed cell ("Braford Colorado") when the coat cell is blank.
     */
    public function breedLine(EntryTroop $troop, string $breedText, ?string $colorText): TroopLineMatch
    {
        $breed = self::normalize($breedText);
        $color = self::normalize((string) $colorText);
        $lines = array_values($troop->breedsByPosition());

        if ($lines === [] && $this->catalog === []) {
            return TroopLineMatch::unknown('breed_text', 'BREED_UNKNOWN_TEXT', 'La orden no declara razas.');
        }

        // A letter of the order's reference is also a way to name the line.
        if (preg_match('/^[A-Z]$/', $breed) === 1) {
            foreach ($lines as $line) {
                if ($line->getLetter() === $breed) {
                    return $color === '' ? TroopLineMatch::matched('breed_text', $line->getPosition()) : $this->coatOf([$line], $color);
                }
            }
        }

        $ofBreed = $breed === '' ? $lines : $this->linesOfBreed($lines, $breed);

        if ($ofBreed === [] && $color === '' && str_contains($breed, ' ')) {
            $split = $this->splitBreedAndCoat($lines, $breed);

            if ($split !== null) {
                return $this->breedLine($troop, $split[0], $split[1]);
            }
        }

        if ($ofBreed === []) {
            return $this->fromCatalog($breed, $color, $lines);
        }

        $breedNames = array_values(array_unique(array_map(fn (EntryOrderBreedEntity $l) => (string) $l->getBreedName(), $ofBreed)));

        if (count($breedNames) > 1 && $breed !== '') {
            return TroopLineMatch::ambiguous(
                'breed_text',
                'BREED_AMBIGUOUS',
                "«{$breedText}» puede ser " . self::orList($breedNames) . ': escribí la raza completa.',
                $breedNames
            );
        }

        return $this->coatOf($ofBreed, $color);
    }

    /**
     * The category line a written name points to. When the animal's sex is known, a name that fits
     * several lines is narrowed to the ones its sex admits.
     */
    public function categoryLine(EntryTroop $troop, string $text, ?AnimalSex $sex): TroopLineMatch
    {
        $normalized = self::normalize($text);
        $lines = $troop->categoriesByPosition();
        $names = array_values(array_map(fn (EntryOrderCategoryEntity $c) => (string) $c->getCategoryName(), $lines));

        // The number of the order's reference is also a way to name the line.
        if (preg_match('/^\d+$/', $normalized) === 1 && isset($lines[(int) $normalized])) {
            return TroopLineMatch::matched('category_text', (int) $normalized);
        }

        $matches = self::bestFits($lines, $normalized, fn (EntryOrderCategoryEntity $c) => [(string) $c->getCategoryName()]);

        if (count($matches) > 1 && $sex !== null) {
            $admitted = array_filter($matches, fn (EntryOrderCategoryEntity $c) => $c->admits($sex));
            $matches = $admitted !== [] ? $admitted : $matches;
        }

        if (count($matches) === 1) {
            return TroopLineMatch::matched('category_text', reset($matches)->getPosition());
        }

        if ($matches === []) {
            return TroopLineMatch::unknown(
                'category_text',
                'CATEGORY_UNKNOWN_TEXT',
                "«{$text}» no es una categoría de la orden: son " . self::andList($names) . '.',
                $names
            );
        }

        $candidates = array_values(array_map(fn (EntryOrderCategoryEntity $c) => (string) $c->getCategoryName(), $matches));

        return TroopLineMatch::ambiguous(
            'category_text',
            'CATEGORY_AMBIGUOUS',
            "«{$text}» puede ser " . self::orList($candidates) . ': escribí la categoría completa.',
            $candidates
        );
    }

    /**
     * The line of one breed a written coat points to. Blank, it is certain only when the order has
     * one line of the breed.
     *
     * @param EntryOrderBreedEntity[] $lines lines of one breed
     */
    private function coatOf(array $lines, string $color): TroopLineMatch
    {
        $coats = array_values(array_unique(array_filter(array_map(fn (EntryOrderBreedEntity $l) => $l->getColorName(), $lines))));
        $breedName = (string) $lines[0]->getBreedName();

        if ($color === '') {
            return count($lines) === 1
                ? TroopLineMatch::matched('breed_text', $lines[0]->getPosition())
                : TroopLineMatch::unknown(
                    'color_text',
                    'COLOR_REQUIRED',
                    "La orden tiene {$breedName} " . self::andList($coats) . ': escribí el pelaje.',
                    $coats
                );
        }

        $matches = self::bestFits(
            $lines,
            $color,
            fn (EntryOrderBreedEntity $l) => array_filter([$l->getColorName(), $l->getColorId() !== null ? ($this->colorCodes[$l->getColorId()] ?? null) : null])
        );

        if (count($matches) === 1) {
            return TroopLineMatch::matched('breed_text', reset($matches)->getPosition());
        }

        if (count($matches) > 1) {
            $candidates = array_values(array_map(fn (EntryOrderBreedEntity $l) => (string) $l->getColorName(), $matches));

            return TroopLineMatch::ambiguous('color_text', 'COLOR_AMBIGUOUS', 'El pelaje puede ser ' . self::orList($candidates) . ': escribilo completo.', $candidates);
        }

        // The order fixed the breed but not its coat: any coat is of that line.
        foreach ($lines as $line) {
            if ($line->getColorId() === null) {
                return TroopLineMatch::matched('breed_text', $line->getPosition());
            }
        }

        // A coat the purchase does not declare for the breed: the animal is another than bought.
        $catalogBreed = $this->catalogBreed($lines[0]->getBreedId());
        $coat = $catalogBreed !== null ? $this->catalogCoat($catalogBreed, $color) : null;

        if ($catalogBreed !== null && $coat !== null) {
            return TroopLineMatch::outsideOrder($catalogBreed->getId(), $coat['id'], "{$catalogBreed->getName()} {$coat['name']}");
        }

        return TroopLineMatch::unknown(
            'color_text',
            'COLOR_UNKNOWN_TEXT',
            "No se reconoce el pelaje escrito: la orden tiene {$breedName} " . self::andList($coats) . '.',
            $coats
        );
    }

    /**
     * A breed the order does not declare, looked up in the catalog.
     *
     * @param EntryOrderBreedEntity[] $lines the order's lines, to say what it declares
     */
    private function fromCatalog(string $breed, string $color, array $lines): TroopLineMatch
    {
        $declared = array_values(array_unique(array_map(fn (EntryOrderBreedEntity $l) => $l->getLabel(), $lines)));
        $matches = $breed === '' ? [] : self::bestFits($this->catalog, $breed, fn (BreedEntity $b) => [$b->getName()]);

        if ($matches === []) {
            return TroopLineMatch::unknown(
                'breed_text',
                'BREED_UNKNOWN_TEXT',
                'No se reconoce la raza escrita' . ($declared !== [] ? ': la orden es de ' . self::andList($declared) : '') . '.',
                $declared
            );
        }

        if (count($matches) > 1) {
            $candidates = array_values(array_map(fn (BreedEntity $b) => $b->getName(), $matches));

            return TroopLineMatch::ambiguous('breed_text', 'BREED_AMBIGUOUS', 'La raza puede ser ' . self::orList($candidates) . ': escribila completa.', $candidates);
        }

        $catalogBreed = reset($matches);

        if ($color === '') {
            return TroopLineMatch::outsideOrder((int) $catalogBreed->getId(), null, $catalogBreed->getName());
        }

        $coat = $this->catalogCoat($catalogBreed, $color);

        if ($coat === null) {
            return TroopLineMatch::unknown('color_text', 'COLOR_UNKNOWN_TEXT', "No se reconoce el pelaje escrito para {$catalogBreed->getName()}.");
        }

        return TroopLineMatch::outsideOrder((int) $catalogBreed->getId(), $coat['id'], "{$catalogBreed->getName()} {$coat['name']}");
    }

    /**
     * "Braford Colorado" written in the breed cell alone: the first words that name a breed, and the
     * rest as its coat.
     *
     * @param EntryOrderBreedEntity[] $lines
     * @return array{0: string, 1: string}|null
     */
    private function splitBreedAndCoat(array $lines, string $text): ?array
    {
        $words = explode(' ', $text);

        for ($i = count($words) - 1; $i >= 1; $i--) {
            $breed = implode(' ', array_slice($words, 0, $i));

            if ($this->linesOfBreed($lines, $breed) !== [] || self::bestFits($this->catalog, $breed, fn (BreedEntity $b) => [$b->getName()]) !== []) {
                return [$breed, implode(' ', array_slice($words, $i))];
            }
        }

        return null;
    }

    /**
     * @param EntryOrderBreedEntity[] $lines
     * @return EntryOrderBreedEntity[]
     */
    private function linesOfBreed(array $lines, string $breed): array
    {
        return array_values(self::bestFits($lines, $breed, fn (EntryOrderBreedEntity $l) => [(string) $l->getBreedName()]));
    }

    private function catalogBreed(int $breedId): ?BreedEntity
    {
        foreach ($this->catalog as $breed) {
            if ($breed->getId() === $breedId) {
                return $breed;
            }
        }

        return null;
    }

    /**
     * A coat the breed admits in the catalog, written by name or code.
     *
     * @return array{id: int, name: string, code: ?string}|null
     */
    private function catalogCoat(BreedEntity $breed, string $color): ?array
    {
        $matches = self::bestFits($breed->getColors(), $color, fn (array $c) => array_filter([$c['name'], $c['code']]));

        return count($matches) === 1 ? reset($matches) : null;
    }

    /**
     * The items whose names the text fits best: exact fits if there are any, otherwise the looser
     * ones (prefix, other gender).
     *
     * @template T
     * @param array<array-key, T> $items
     * @param callable(T): array<string> $namesOf
     * @return array<array-key, T>
     */
    private static function bestFits(array $items, string $text, callable $namesOf): array
    {
        if ($text === '') {
            return [];
        }

        $exact = [];
        $loose = [];

        foreach ($items as $key => $item) {
            $fit = 0;

            foreach ($namesOf($item) as $name) {
                $fit = max($fit, self::fit($text, self::normalize((string) $name)));
            }

            if ($fit === 2) {
                $exact[$key] = $item;
            } elseif ($fit === 1) {
                $loose[$key] = $item;
            }
        }

        return $exact !== [] ? $exact : $loose;
    }

    /**
     * 2: the name itself. 1: a prefix of three letters or more, or the same word in the other
     * gender. 0: no fit.
     */
    private static function fit(string $text, string $name): int
    {
        if ($name === '') {
            return 0;
        }

        if ($text === $name || str_replace(' ', '', $text) === str_replace(' ', '', $name)) {
            return 2;
        }

        if (mb_strlen($text) >= self::MIN_PREFIX_LENGTH && str_starts_with($name, $text)) {
            return 1;
        }

        // "COLORADA" for "COLORADO", "NEGRA" for "NEGRO".
        return str_ends_with($text, 'A') && str_ends_with($name, 'O') && substr($text, 0, -1) === substr($name, 0, -1) ? 1 : 0;
    }

    /**
     * Upper case, no accents, no punctuation: "Col." → "COL", "Aberdeen-Angus" → "ABERDEEN ANGUS".
     */
    public static function normalize(string $text): string
    {
        $upper = mb_strtoupper(trim($text));
        $plain = strtr($upper, ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N']);

        return trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^A-Z0-9 ]/', ' ', $plain)));
    }

    /**
     * @param list<string> $words
     */
    private static function orList(array $words): string
    {
        return self::joinList($words, ' o ');
    }

    /**
     * @param list<string> $words
     */
    private static function andList(array $words): string
    {
        return self::joinList($words, ' y ');
    }

    /**
     * @param list<string> $words
     */
    private static function joinList(array $words, string $last): string
    {
        $words = array_values($words);

        return count($words) <= 1 ? (string) ($words[0] ?? '') : implode(', ', array_slice($words, 0, -1)) . $last . end($words);
    }
}
