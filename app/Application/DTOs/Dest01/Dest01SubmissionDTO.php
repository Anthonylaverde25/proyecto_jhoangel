<?php

declare(strict_types=1);

namespace App\Application\DTOs\Dest01;

/**
 * DEST-01: weaning sheet. The header names the weaning batch and the date; each row is a calf.
 * The rows of every scanned page arrive merged in a single submission.
 *
 * The destination is declared by the operator while reviewing the scan: either an existing
 * weaning batch (targetBatchId) or a new one to create (newBatchName), never both.
 */
final readonly class Dest01SubmissionDTO
{
    /**
     * @param array<int, array{caravana: string, caravana_madre: ?string, peso: ?float, observations: ?string}> $rows
     */
    public function __construct(
        public int $companyId,
        public ?int $targetBatchId,
        public ?string $newBatchName,
        public string $fechaDestete,
        public ?string $tipoDestete,
        public ?string $loteOrigen,
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
            $weight = $row['peso'] ?? null;

            $rows[] = [
                'caravana' => trim((string) ($row['caravana'] ?? '')),
                'caravana_madre' => self::nullableString($row['caravana_madre'] ?? null),
                'peso' => $weight === null || $weight === '' ? null : (float) $weight,
                'observations' => self::nullableString($row['observations'] ?? null),
            ];
        }

        return new self(
            companyId: $companyId,
            targetBatchId: isset($data['target_batch_id']) ? (int) $data['target_batch_id'] : null,
            newBatchName: self::nullableString($data['new_batch_name'] ?? null),
            fechaDestete: (string) ($data['fecha_destete'] ?? ''),
            tipoDestete: self::nullableString($data['tipo_destete'] ?? null),
            loteOrigen: self::nullableString($data['lote_origen'] ?? null),
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
