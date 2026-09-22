<?php

declare(strict_types=1);

namespace App\Application\DTOs\Cact01;

/**
 * CACT-01: change of productive activity, measured at the chute.
 *
 * The header names the source batch and the date; each row is one animal, with the
 * weight it enters the new activity with and the dentition read that day.
 *
 * Destinations arrive already RESOLVED. The sheet carries names, but by the time the
 * submission reaches the endpoint the operator has confirmed, for each distinct name
 * read, whether it is an existing batch or one to create. The backend never looks a
 * batch up by name, exactly as in DEST-01: OCR text is not an identifier.
 *
 * `dientes` travels as the raw string rather than an int. Deciding between "the cell
 * was blank" and "the cell says something unreadable" belongs to the use case, and
 * CaravanValueParser::parseTeeth() collapses both into 0.
 */
final readonly class Cact01SubmissionDTO
{
    /**
     * @param list<array{key: string, target_batch_id: ?int, new_batch: ?array{name: string, activity_id: int, batch_type_id: int, is_confined: ?bool}}> $destinations
     * @param list<array{caravana: string, peso_actual: ?float, sexo: ?string, categoria: ?string, dientes: ?string, destination_key: string, observations: ?string}> $rows
     */
    public function __construct(
        public int $companyId,
        public int $sourceBatchId,
        public string $fechaMovimiento,
        public ?string $actividadOrigen,
        public ?string $actividadDestino,
        public ?string $sistemaManejo,
        public ?float $totalCabezas,
        public ?float $pesoTotal,
        public ?string $responsable,
        public ?string $observaciones,
        public array $destinations,
        public array $rows
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data, int $companyId): self
    {
        $destinations = [];
        foreach ((array) ($data['destinations'] ?? []) as $destination) {
            $newBatch = $destination['new_batch'] ?? null;

            $destinations[] = [
                'key' => self::normalizeKey($destination['key'] ?? ''),
                'target_batch_id' => isset($destination['target_batch_id'])
                    ? (int) $destination['target_batch_id']
                    : null,
                'new_batch' => is_array($newBatch) ? [
                    'name' => trim((string) ($newBatch['name'] ?? '')),
                    'activity_id' => (int) ($newBatch['activity_id'] ?? 0),
                    'batch_type_id' => (int) ($newBatch['batch_type_id'] ?? 0),
                    // Three states, as in the batches table: the operator may confirm a
                    // destination without answering how it will be fed, and the use case
                    // is the one that decides whether that is acceptable.
                    'is_confined' => array_key_exists('is_confined', $newBatch) && $newBatch['is_confined'] !== null
                        ? (bool) $newBatch['is_confined']
                        : null,
                ] : null,
            ];
        }

        $rows = [];
        foreach ((array) ($data['rows'] ?? []) as $row) {
            $weight = $row['peso_actual'] ?? null;

            $rows[] = [
                'caravana' => trim((string) ($row['caravana'] ?? '')),
                'peso_actual' => $weight === null || $weight === '' ? null : (float) $weight,
                'sexo' => self::nullableString($row['sexo'] ?? null),
                'categoria' => self::nullableString($row['categoria'] ?? null),
                'dientes' => self::nullableString($row['dientes'] ?? null),
                'destination_key' => self::normalizeKey($row['destination_key'] ?? ''),
                'observations' => self::nullableString($row['observations'] ?? null),
            ];
        }

        return new self(
            companyId: $companyId,
            sourceBatchId: (int) ($data['source_batch_id'] ?? 0),
            fechaMovimiento: (string) ($data['fecha_movimiento'] ?? ''),
            actividadOrigen: self::nullableString($data['actividad_origen'] ?? null),
            actividadDestino: self::nullableString($data['actividad_destino'] ?? null),
            sistemaManejo: self::nullableString($data['sistema_manejo'] ?? null),
            totalCabezas: self::nullableFloat($data['total_cabezas'] ?? null),
            pesoTotal: self::nullableFloat($data['peso_total'] ?? null),
            responsable: self::nullableString($data['responsable'] ?? null),
            observaciones: self::nullableString($data['observaciones'] ?? null),
            destinations: $destinations,
            rows: $rows
        );
    }

    /**
     * Destination keys are read off paper, so "Recría Norte", "recria norte " and
     * "RECRIA  NORTE" all have to meet. Normalised once here and used as the join between
     * rows and destinations.
     *
     * Deliberately the same rule as `normalizeDestinationKey` on the frontend, accents
     * included: the two sides of this key have to agree or a row silently loses its
     * destination, and a scan mangling an accent is the likeliest way that happens.
     */
    public static function normalizeKey(mixed $value): string
    {
        $value = trim((string) ($value ?? ''));

        if ($value === '') {
            return '';
        }

        $value = strtr($value, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
        ]);

        return mb_strtoupper((string) preg_replace('/\s+/u', ' ', $value));
    }

    private static function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    private static function nullableFloat(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : (float) $value;
    }
}
