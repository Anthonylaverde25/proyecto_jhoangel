<?php

declare(strict_types=1);

namespace App\Application\DTOs\Dest01;

use App\Application\DTOs\Cact01\Cact01SubmissionDTO;

/**
 * DEST-01: weaning sheet. The header names the date, the weaning type and — with one destination
 * for every calf — the weaning batch; each row is a calf. The rows of every scanned page arrive
 * merged in a single submission.
 *
 * Destinations arrive already RESOLVED, as in CACT-01: for each weaning batch named on the paper,
 * the operator confirmed whether it is an existing batch or one to create. The backend never looks
 * a batch up by the name read off paper: OCR text is not an identifier.
 *
 * Two destination modes, like CACT-01:
 * - `single`: the header names the batch every calf goes to. Rows carry no destination.
 * - `per_animal`: the header names none; each row names the batch of its calf. A row without one
 *   is a calf without destination, never completed from the header.
 *
 * The same submission is what the screen builds when it executes an order or registers a weaning
 * (`origin`), so every weaning goes through one set of checks.
 */
final readonly class Dest01SubmissionDTO
{
    public const ORIGIN_SHEET = 'SHEET';
    public const ORIGIN_SCREEN = 'SCREEN';
    /** "Registrar destete": the weaning already happened and is loaded afterwards. Internal only. */
    public const ORIGIN_REGISTRATION = 'REGISTRATION';

    public const MODE_SINGLE = 'single';
    public const MODE_PER_ANIMAL = 'per_animal';

    /** The key of the one destination of a legacy submission (a bare target_batch_id / new_batch_name). */
    public const SINGLE_KEY = 'DESTETE';

    /**
     * @param list<array{key: string, target_batch_id: ?int, new_batch: ?array{name: string, is_confined: ?bool}}> $destinations
     * @param array<int, array{caravana: string, caravana_madre: ?string, peso: ?float, observations: ?string, destination_key: string, manejo: ?bool, cs_nueva: ?string, category_id?: ?int, subcategory_id?: ?int}> $rows
     *        `cs_nueva` is the C/S cell as read; `category_id` / `subcategory_id` are internal, set only by a screen.
     */
    public function __construct(
        public int $companyId,
        public string $fechaDestete,
        public ?string $tipoDestete,
        public ?string $loteOrigen,
        public ?string $responsable,
        public ?string $observaciones,
        public array $rows,
        public string $destinationMode = self::MODE_SINGLE,
        public array $destinations = [],
        /** The CORRAL / PASTURA box of the header: the management system of the one new batch. */
        public ?string $sistemaManejo = null,
        public string $origin = self::ORIGIN_SHEET,
        /** The weaning order this sheet fulfils. Null: a sheet printed blank, or no order at all. */
        public ?int $weaningOrderId = null,
        public ?int $actionUserId = null,
        /**
         * The order code as the paper carries it, whether or not it resolved. Blank means the sheet
         * was printed blank and its order is created on confirming; a code that did not resolve to
         * `weaningOrderId` is rejected instead.
         */
        public ?string $paperOrderCode = null
    ) {
    }

    /**
     * The same submission with its destinations replaced. Used when an order already knows the
     * batch a destination resolved to on an earlier round.
     *
     * @param list<array{key: string, target_batch_id: ?int, new_batch: ?array{name: string, is_confined: ?bool}}> $destinations
     */
    public function withDestinations(array $destinations): self
    {
        return new self(
            companyId: $this->companyId,
            fechaDestete: $this->fechaDestete,
            tipoDestete: $this->tipoDestete,
            loteOrigen: $this->loteOrigen,
            responsable: $this->responsable,
            observaciones: $this->observaciones,
            rows: $this->rows,
            destinationMode: $this->destinationMode,
            destinations: array_values($destinations),
            sistemaManejo: $this->sistemaManejo,
            origin: $this->origin,
            weaningOrderId: $this->weaningOrderId,
            actionUserId: $this->actionUserId,
            paperOrderCode: $this->paperOrderCode
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data, int $companyId, ?int $actionUserId = null): self
    {
        $mode = ($data['destination_mode'] ?? null) === self::MODE_PER_ANIMAL ? self::MODE_PER_ANIMAL : self::MODE_SINGLE;
        $destinations = [];

        foreach ((array) ($data['destinations'] ?? []) as $destination) {
            $newBatch = $destination['new_batch'] ?? null;

            $destinations[] = [
                'key' => Cact01SubmissionDTO::normalizeKey($destination['key'] ?? ''),
                'target_batch_id' => isset($destination['target_batch_id']) ? (int) $destination['target_batch_id'] : null,
                'new_batch' => is_array($newBatch) ? [
                    'name' => trim((string) ($newBatch['name'] ?? '')),
                    'is_confined' => array_key_exists('is_confined', $newBatch) && $newBatch['is_confined'] !== null
                        ? (bool) $newBatch['is_confined']
                        : null,
                ] : null,
            ];
        }

        // The shape every DEST-01 load had before orders: one batch, named on the header.
        if ($destinations === [] && (isset($data['target_batch_id']) || self::nullableString($data['new_batch_name'] ?? null) !== null)) {
            $mode = self::MODE_SINGLE;
            $destinations[] = [
                'key' => self::SINGLE_KEY,
                'target_batch_id' => isset($data['target_batch_id']) ? (int) $data['target_batch_id'] : null,
                'new_batch' => isset($data['target_batch_id']) ? null : [
                    'name' => (string) self::nullableString($data['new_batch_name'] ?? null),
                    'is_confined' => array_key_exists('new_batch_is_confined', $data) && $data['new_batch_is_confined'] !== null
                        ? (bool) $data['new_batch_is_confined']
                        : null,
                ],
            ];
        }

        $rows = [];
        foreach ((array) ($data['rows'] ?? []) as $row) {
            $weight = $row['peso'] ?? null;

            $rows[] = [
                'caravana' => trim((string) ($row['caravana'] ?? '')),
                'caravana_madre' => self::nullableString($row['caravana_madre'] ?? null),
                'peso' => $weight === null || $weight === '' ? null : (float) $weight,
                'observations' => self::nullableString($row['observations'] ?? null),
                'destination_key' => Cact01SubmissionDTO::normalizeKey($row['destination_key'] ?? $row['lote_destino'] ?? ''),
                'manejo' => Cact01SubmissionDTO::management($row['manejo'] ?? null),
                'cs_nueva' => self::nullableString($row['cs_nueva'] ?? null),
            ];
        }

        return new self(
            companyId: $companyId,
            fechaDestete: (string) ($data['fecha_destete'] ?? ''),
            tipoDestete: self::nullableString($data['tipo_destete'] ?? null),
            loteOrigen: self::nullableString($data['lote_origen'] ?? null),
            responsable: self::nullableString($data['responsable'] ?? null),
            observaciones: self::nullableString($data['observaciones'] ?? null),
            rows: $rows,
            destinationMode: $mode,
            destinations: $destinations,
            sistemaManejo: self::nullableString($data['sistema_manejo'] ?? null),
            origin: self::ORIGIN_SHEET,
            weaningOrderId: isset($data['weaning_order_id']) ? (int) $data['weaning_order_id'] : null,
            actionUserId: $actionUserId,
            paperOrderCode: self::nullableString($data['orden_destete'] ?? null)
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
