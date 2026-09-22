<?php

declare(strict_types=1);

namespace App\Application\DTOs\Lser01;

/**
 * LSER-01: single-bull service batch sheet. The bull is declared in the header and the
 * females are the rows of the table.
 */
final readonly class Lser01SubmissionDTO
{
    /**
     * @param array<int, array{caravana: string, observations: ?string}> $rows
     */
    public function __construct(
        public int $companyId,
        public string $lote,
        public string $toroCaravana,
        public string $plannedStartDate,
        public ?string $plannedEndDate,
        public ?string $responsable,
        public ?string $observaciones,
        public array $rows
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data, int $companyId): self
    {
        $rows = [];
        foreach ((array) ($data['rows'] ?? []) as $row) {
            $rows[] = [
                'caravana' => trim((string) ($row['caravana'] ?? '')),
                'observations' => self::nullableString($row['observations'] ?? null),
            ];
        }

        return new self(
            companyId: $companyId,
            lote: trim((string) ($data['lote'] ?? '')),
            toroCaravana: trim((string) ($data['toro_caravana'] ?? '')),
            plannedStartDate: (string) ($data['planned_start_date'] ?? ''),
            plannedEndDate: self::nullableString($data['planned_end_date'] ?? null),
            responsable: self::nullableString($data['responsable'] ?? null),
            observaciones: self::nullableString($data['observaciones'] ?? null),
            rows: $rows
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
