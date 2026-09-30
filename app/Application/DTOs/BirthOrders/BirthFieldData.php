<?php

declare(strict_types=1);

namespace App\Application\DTOs\BirthOrders;

/**
 * What the round found for one female, when the calvings are loaded from a screen ("Registrar
 * partos", "Ejecutar orden") instead of from a scanned sheet. A female without an outcome was not
 * resolved this time and stays pending.
 */
final readonly class BirthFieldData
{
    public function __construct(
        public ?string $outcome = null,
        public ?string $calfIdentification = null,
        public ?string $calfSex = null,
        public ?float $calfWeight = null,
        public ?int $calfBreedId = null,
        public int $calfTeeth = 0,
        /** Optional: left empty, the gestation's single or confirmed sire is used, or it waits in "Sires pendientes". */
        public ?int $fatherId = null,
        public ?string $birthDate = null,
        public ?string $observations = null
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $animals the validated `animals` of the request
     * @return array<int, self> keyed by mother caravan id, every female that was sent
     */
    public static function mapFromAnimals(array $animals): array
    {
        $byMother = [];

        foreach ($animals as $animal) {
            $weight = $animal['calf_weight'] ?? null;

            $byMother[(int) $animal['caravan_id']] = new self(
                outcome: self::text($animal['outcome'] ?? null),
                calfIdentification: self::text($animal['calf_identification'] ?? null),
                calfSex: self::text($animal['calf_sex'] ?? null),
                calfWeight: $weight !== null && $weight !== '' ? (float) $weight : null,
                calfBreedId: isset($animal['calf_breed_id']) && $animal['calf_breed_id'] !== '' ? (int) $animal['calf_breed_id'] : null,
                calfTeeth: isset($animal['calf_teeth']) && $animal['calf_teeth'] !== '' ? (int) $animal['calf_teeth'] : 0,
                fatherId: isset($animal['father_id']) && $animal['father_id'] !== '' ? (int) $animal['father_id'] : null,
                birthDate: self::text($animal['birth_date'] ?? null),
                observations: self::text($animal['observations'] ?? null)
            );
        }

        return $byMother;
    }

    public function hasOutcome(): bool
    {
        return $this->outcome !== null;
    }

    private static function text(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value !== '' ? $value : null;
    }
}
