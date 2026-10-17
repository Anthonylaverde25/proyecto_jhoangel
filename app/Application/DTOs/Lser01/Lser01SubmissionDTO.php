<?php

declare(strict_types=1);

namespace App\Application\DTOs\Lser01;

/**
 * LSER-01: service start record over an existing batch. The batch and the bull are declared in
 * the header and the females of the batch are the rows of the table.
 */
final readonly class Lser01SubmissionDTO
{
    /**
     * @param array<int, array{caravana: string, observations: ?string}> $rows
     */
    public function __construct(
        public int $companyId,
        public int $userId,
        public int $batchId,
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
    public static function fromArray(array $data, int $companyId, int $userId): self
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
            userId: $userId,
            batchId: (int) ($data['batch_id'] ?? 0),
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
