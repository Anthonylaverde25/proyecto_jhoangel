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
    public const ORIGIN_SHEET = 'SHEET';
    public const ORIGIN_SCREEN = 'SCREEN';
    /** "Registrar transferencia": the movement already happened and is loaded afterwards. Internal only. */
    public const ORIGIN_REGISTRATION = 'REGISTRATION';


    /**
     * @param list<array{key: string, target_batch_id: ?int, new_batch: ?array{name: string, activity_id: int, batch_type_id: int, is_confined: ?bool}}> $destinations
     * @param list<array{caravana: string, peso_actual: ?float, categoria: ?string, dientes: ?string, destination_key: string, manejo: ?bool, observations: ?string, cs_nueva?: ?string, category_id?: ?int, subcategory_id?: ?int}> $rows
     *        `categoria` is the current category printed to find the animal, and never overwrites it.
     *        `cs_nueva` is the C/S cell: the new category or subcategory written at the chute, as
     *        read. Resolved against the catalog by the use case, never trusted as an id.
     *        `category_id` / `subcategory_id` are internal: only "Registrar transferencia" sets them.
     */
    public function __construct(
        public int $companyId,
        public int $sourceBatchId,
        public string $fechaMovimiento,
        public ?string $actividadOrigen,
        /**
         * The destination activity of the whole sheet, all its pages included.
         *
         * Not a label like `actividadDestino` below, which is what the paper said: this is
         * what every destination is validated against, so that a movement declared towards one
         * productive stage cannot land in a batch of another.
         */
        public int $actividadDestinoId,
        public ?string $actividadDestino,
        public ?string $sistemaManejo,
        public ?float $totalCabezas,
        public ?float $pesoTotal,
        public ?string $responsable,
        public ?string $observaciones,
        public array $destinations,
        public array $rows,
        /**
         * Where the order was born: a scanned sheet, or the transfer screen. It only ever
         * reaches the notes of the movement, but it matters there — a movement recorded
         * from a screen must not leave a history claiming there was a sheet.
         */
        public string $origin = self::ORIGIN_SHEET,
        /**
         * The transfer order this sheet fulfils. Null is the decision that the order is not
         * mandatory: a blank sheet, or a screen transfer without an order, still goes through.
         */
        public ?int $transferOrderId = null,
        /** Who is registering the movement; what the order's history attributes it to. */
        public ?int $actionUserId = null,
        /**
         * The order code as the paper carries it, whether or not it resolved. Blank means the
         * sheet was printed blank and its order is created on confirming; a code that did not
         * resolve to `transferOrderId` is rejected instead.
         */
        public ?string $paperOrderCode = null
    ) {
    }

    /**
     * The same submission with its destinations replaced. Used when an order already knows the
     * batch a destination resolved to on an earlier round.
     *
     * @param list<array{key: string, target_batch_id: ?int, new_batch: ?array{name: string, activity_id: int, batch_type_id: int, is_confined: ?bool}}> $destinations
     */
    public function withDestinations(array $destinations): self
    {
        return new self(
            companyId: $this->companyId,
            sourceBatchId: $this->sourceBatchId,
            fechaMovimiento: $this->fechaMovimiento,
            actividadOrigen: $this->actividadOrigen,
            actividadDestinoId: $this->actividadDestinoId,
            actividadDestino: $this->actividadDestino,
            sistemaManejo: $this->sistemaManejo,
            totalCabezas: $this->totalCabezas,
            pesoTotal: $this->pesoTotal,
            responsable: $this->responsable,
            observaciones: $this->observaciones,
            destinations: array_values($destinations),
            rows: $this->rows,
            origin: $this->origin,
            transferOrderId: $this->transferOrderId,
            actionUserId: $this->actionUserId,
            paperOrderCode: $this->paperOrderCode
        );
    }

    /**
     * The same submission as a sheet that names no order: what "Obtener orden de transferencia"
     * validates, since the order is asked for precisely because the code on paper found none.
     */
    public function withoutOrder(): self
    {
        return new self(
            companyId: $this->companyId,
            sourceBatchId: $this->sourceBatchId,
            fechaMovimiento: $this->fechaMovimiento,
            actividadOrigen: $this->actividadOrigen,
            actividadDestinoId: $this->actividadDestinoId,
            actividadDestino: $this->actividadDestino,
            sistemaManejo: $this->sistemaManejo,
            totalCabezas: $this->totalCabezas,
            pesoTotal: $this->pesoTotal,
            responsable: $this->responsable,
            observaciones: $this->observaciones,
            destinations: $this->destinations,
            rows: $this->rows,
            origin: $this->origin,
            transferOrderId: null,
            actionUserId: $this->actionUserId,
            paperOrderCode: null
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data, int $companyId, ?int $actionUserId = null): self
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
                'categoria' => self::nullableString($row['categoria'] ?? null),
                'dientes' => self::nullableString($row['dientes'] ?? null),
                'destination_key' => self::normalizeKey($row['destination_key'] ?? ''),
                // The M cell, normalised the same way the header box is: three states, because
                // a blank cell is a question still open, not a "no".
                'manejo' => self::management($row['manejo'] ?? null),
                'observations' => self::nullableString($row['observations'] ?? null),
                'cs_nueva' => self::nullableString($row['cs_nueva'] ?? null),
            ];
        }

        return new self(
            companyId: $companyId,
            sourceBatchId: (int) ($data['source_batch_id'] ?? 0),
            fechaMovimiento: (string) ($data['fecha_movimiento'] ?? ''),
            actividadOrigen: self::nullableString($data['actividad_origen'] ?? null),
            actividadDestinoId: (int) ($data['actividad_destino_id'] ?? 0),
            actividadDestino: self::nullableString($data['actividad_destino'] ?? null),
            sistemaManejo: self::nullableString($data['sistema_manejo'] ?? null),
            totalCabezas: self::nullableFloat($data['total_cabezas'] ?? null),
            pesoTotal: self::nullableFloat($data['peso_total'] ?? null),
            responsable: self::nullableString($data['responsable'] ?? null),
            observaciones: self::nullableString($data['observaciones'] ?? null),
            destinations: $destinations,
            rows: $rows,
            origin: ($data['origin'] ?? null) === self::ORIGIN_SCREEN ? self::ORIGIN_SCREEN : self::ORIGIN_SHEET,
            transferOrderId: isset($data['transfer_order_id']) ? (int) $data['transfer_order_id'] : null,
            actionUserId: $actionUserId,
            paperOrderCode: self::nullableString($data['orden_transferencia'] ?? null)
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

    /**
     * The management system as written: 'C'/'CORRAL' penned, 'P'/'PASTURA' grazing, anything
     * else unanswered.
     *
     * Deliberately the same criterion the header box uses, because the two cells mean exactly
     * the same thing about a batch and must not drift apart.
     */
    public static function management(mixed $value): ?bool
    {
        $value = mb_strtoupper(trim((string) ($value ?? '')));

        if ($value === '') {
            return null;
        }

        if ($value === 'C' || str_contains($value, 'CORRAL')) {
            return true;
        }

        if ($value === 'P' || str_contains($value, 'PASTURA') || str_contains($value, 'CAMPO')
            || str_contains($value, 'EXTENSIV')) {
            return false;
        }

        return null;
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
