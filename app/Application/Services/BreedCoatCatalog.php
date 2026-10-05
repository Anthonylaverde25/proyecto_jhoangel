<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Core\Entities\BreedEntity;

/**
 * The breeds and coat colours (pelajes) a sheet's calf can be written with, read once per sheet.
 *
 * A breed or a coat is found by its name — accents, case and spaces aside — and a coat also by its
 * code (NE, CO…). A coat is valid for a breed when the breed admits it (breed_color), the same rule
 * an entry order applies; a calf without a breed may have any coat of the catalog.
 */
final class BreedCoatCatalog
{
    /**
     * @param array<string, int> $breedIdByName normalised name => id
     * @param array<int, list<int>> $colorIdsByBreed breed id => admitted colour ids
     * @param array<string, int> $colorIdByText normalised name or code => id
     * @param array<int, string> $colorNames id => name
     */
    private function __construct(
        private readonly array $breedIdByName,
        private readonly array $colorIdsByBreed,
        private readonly array $colorIdByText,
        private readonly array $colorNames
    ) {
    }

    /**
     * @param BreedEntity[] $breeds with their colours loaded
     */
    public static function fromBreeds(array $breeds): self
    {
        $breedIdByName = [];
        $colorIdsByBreed = [];
        $colorIdByText = [];
        $colorNames = [];

        foreach ($breeds as $breed) {
            $breedId = (int) $breed->getId();
            $breedIdByName[self::normalize($breed->getName())] = $breedId;
            $colorIdsByBreed[$breedId] = [];

            foreach ($breed->getColors() as $color) {
                $colorIdsByBreed[$breedId][] = $color['id'];
                $colorNames[$color['id']] = $color['name'];
                $colorIdByText[self::normalize($color['name'])] = $color['id'];

                if ($color['code'] !== null && $color['code'] !== '') {
                    $colorIdByText[self::normalize($color['code'])] ??= $color['id'];
                }
            }
        }

        return new self($breedIdByName, $colorIdsByBreed, $colorIdByText, $colorNames);
    }

    public function breedIdByName(string $name): ?int
    {
        return $this->breedIdByName[self::normalize($name)] ?? null;
    }

    public function hasBreed(int $breedId): bool
    {
        return array_key_exists($breedId, $this->colorIdsByBreed);
    }

    public function colorIdByText(string $text): ?int
    {
        return $this->colorIdByText[self::normalize($text)] ?? null;
    }

    public function hasColor(int $colorId): bool
    {
        return array_key_exists($colorId, $this->colorNames);
    }

    public function colorName(int $colorId): string
    {
        return $this->colorNames[$colorId] ?? (string) $colorId;
    }

    /**
     * Whether the breed admits the coat. A breed without coats loaded admits none.
     */
    public function admits(int $breedId, int $colorId): bool
    {
        return in_array($colorId, $this->colorIdsByBreed[$breedId] ?? [], true);
    }

    public static function normalize(string $text): string
    {
        $text = strtr(mb_strtolower(trim($text)), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n']);

        return (string) preg_replace('/[^a-z0-9]+/', '', $text);
    }
}
