<?php

declare(strict_types=1);

namespace App\Application\DTOs\WeaningOrders;

/**
 * What was measured or decided at the chute for one calf, when the weaning is loaded from a
 * screen ("Registrar destete", "Ejecutar orden") instead of from a scanned sheet.
 *
 * A calf present here was looked at: a null category means "keeps its category", decided, which
 * is how an order whose category is decided at the chute is executed without its sheet.
 */
final readonly class WeaningFieldData
{
    public function __construct(
        public ?float $weightKg = null,
        public ?string $observations = null,
        public ?int $categoryId = null,
        /** Narrows `categoryId`; null is the category alone. Never sent without it. */
        public ?int $subcategoryId = null
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $animals the validated `animals` of the request
     * @return array<int, self> keyed by caravan id, every calf that was sent
     */
    public static function mapFromAnimals(array $animals): array
    {
        $byCaravan = [];

        foreach ($animals as $animal) {
            $observations = trim((string) ($animal['observations'] ?? ''));
            $weight = $animal['weight'] ?? null;

            $byCaravan[(int) $animal['caravan_id']] = new self(
                weightKg: $weight !== null && $weight !== '' ? (float) $weight : null,
                observations: $observations !== '' ? $observations : null,
                categoryId: isset($animal['category_id']) ? (int) $animal['category_id'] : null,
                subcategoryId: isset($animal['category_id'], $animal['subcategory_id']) ? (int) $animal['subcategory_id'] : null
            );
        }

        return $byCaravan;
    }
}
