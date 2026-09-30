<?php

declare(strict_types=1);

namespace App\Application\DTOs\TransferOrders;

/**
 * What was measured or decided at the chute for one animal of a registered transfer. A null
 * field means "no change": only what changed is written.
 */
final readonly class RegisterFieldData
{
    public function __construct(
        public ?float $weightKg = null,
        /** DL, 2D, 4D, 6D or 8D (full mouth): the same text a sheet carries. */
        public ?string $teeth = null,
        public ?int $categoryId = null,
        public ?string $observations = null,
        /** Narrows `categoryId`; null is the category alone. Never sent without it. */
        public ?int $subcategoryId = null
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $animals the validated `animals` of the request
     * @return array<int, self> keyed by caravan id, only animals that carry something
     */
    public static function mapFromAnimals(array $animals): array
    {
        $byCaravan = [];

        foreach ($animals as $animal) {
            $observations = trim((string) ($animal['observations'] ?? ''));
            $data = new self(
                weightKg: isset($animal['current_weight']) ? (float) $animal['current_weight'] : null,
                teeth: isset($animal['teeth']) && $animal['teeth'] !== '' ? (string) $animal['teeth'] : null,
                categoryId: isset($animal['category_id']) ? (int) $animal['category_id'] : null,
                observations: $observations !== '' ? $observations : null,
                subcategoryId: isset($animal['category_id'], $animal['subcategory_id']) ? (int) $animal['subcategory_id'] : null
            );

            if ($data->weightKg !== null || $data->teeth !== null || $data->categoryId !== null || $data->observations !== null) {
                $byCaravan[(int) $animal['caravan_id']] = $data;
            }
        }

        return $byCaravan;
    }
}
