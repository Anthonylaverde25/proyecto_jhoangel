<?php

declare(strict_types=1);

namespace App\Application\DTOs\Par01;

/**
 * PAR-01: calving sheet. The header names the order it fulfils, the lots and the day of the round;
 * each row is a pregnant female and what the round found — a live calf with its tag, sex, weight,
 * breed, coat (pelaje) and birth date, a stillbirth or an abortion. The rows of every scanned page arrive merged
 * in a single submission.
 *
 * There is no destination: each calf is born in the batch its mother is in, read by the server.
 * A female written in a free line that the order did not list carries the "Fuera de orden" box
 * crossed (`fuera_de_orden`): a calving outside the plan is declared, never inferred.
 *
 * The sheet prints neither the sire nor the teeth. The sire is chosen, optionally, in the review or
 * on the screen (`father_id`); the teeth default to 0.
 *
 * The same submission is what the screen builds when it executes an order or registers calvings
 * (`origin`), so every calving goes through one set of checks.
 */
final readonly class Par01SubmissionDTO
{
    public const ORIGIN_SHEET = 'SHEET';
    public const ORIGIN_SCREEN = 'SCREEN';
    /** "Registrar partos": the calvings already happened and are loaded afterwards. Internal only. */
    public const ORIGIN_REGISTRATION = 'REGISTRATION';

    /**
     * @param array<int, array{caravana_madre: string, resultado: ?string, caravana_cria: ?string, sexo: ?string, peso: ?float, raza: ?string, breed_id: ?int, pelaje: ?string, color_id: ?int, dientes: int, father_id: ?int, fecha_nacimiento: ?string, observations: ?string, fuera_de_orden: bool}> $rows
     */
    public function __construct(
        public int $companyId,
        public array $rows,
        /** The day of the round. Informative: it never fills the date of a row. */
        public ?string $fechaRecorrida = null,
        public ?string $lote = null,
        public ?string $responsable = null,
        public ?string $observaciones = null,
        public string $origin = self::ORIGIN_SHEET,
        /** The birth order this sheet fulfils. Null: a sheet printed blank, or no order at all. */
        public ?int $birthOrderId = null,
        public ?int $actionUserId = null,
        /**
         * The order code as the paper carries it, whether or not it resolved. Blank means the sheet
         * was printed blank and its order is created on confirming; a code that did not resolve to
         * `birthOrderId` is rejected instead.
         */
        public ?string $paperOrderCode = null
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data, int $companyId, ?int $actionUserId = null): self
    {
        $rows = [];
        foreach ((array) ($data['rows'] ?? []) as $row) {
            $rows[] = self::row($row);
        }

        return new self(
            companyId: $companyId,
            rows: $rows,
            fechaRecorrida: self::nullableString($data['fecha_recorrida'] ?? null),
            lote: self::nullableString($data['lote'] ?? null),
            responsable: self::nullableString($data['responsable'] ?? null),
            observaciones: self::nullableString($data['observaciones'] ?? null),
            origin: self::ORIGIN_SHEET,
            birthOrderId: isset($data['birth_order_id']) ? (int) $data['birth_order_id'] : null,
            actionUserId: $actionUserId,
            paperOrderCode: self::nullableString($data['orden_paricion'] ?? null)
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return array{caravana_madre: string, resultado: ?string, caravana_cria: ?string, sexo: ?string, peso: ?float, raza: ?string, breed_id: ?int, pelaje: ?string, color_id: ?int, dientes: int, father_id: ?int, fecha_nacimiento: ?string, observations: ?string, fuera_de_orden: bool}
     */
    public static function row(array $row): array
    {
        $weight = $row['peso'] ?? null;
        $teeth = $row['dientes'] ?? null;

        return [
            'caravana_madre' => trim((string) ($row['caravana_madre'] ?? '')),
            'resultado' => self::nullableString($row['resultado'] ?? null),
            'caravana_cria' => self::nullableString($row['caravana_cria'] ?? null),
            'sexo' => self::nullableString($row['sexo'] ?? null),
            'peso' => $weight === null || $weight === '' ? null : (float) $weight,
            'raza' => self::nullableString($row['raza'] ?? null),
            'breed_id' => isset($row['breed_id']) && $row['breed_id'] !== '' ? (int) $row['breed_id'] : null,
            'pelaje' => self::nullableString($row['pelaje'] ?? null),
            'color_id' => isset($row['color_id']) && $row['color_id'] !== '' ? (int) $row['color_id'] : null,
            'dientes' => $teeth === null || $teeth === '' ? 0 : (int) $teeth,
            'father_id' => isset($row['father_id']) && $row['father_id'] !== '' ? (int) $row['father_id'] : null,
            'fecha_nacimiento' => self::nullableString($row['fecha_nacimiento'] ?? null),
            'observations' => self::nullableString($row['observations'] ?? null),
            'fuera_de_orden' => self::crossed($row['fuera_de_orden'] ?? null),
        ];
    }

    /**
     * Whether a box is crossed: true from a screen, or the mark the scan reads ("X", a tick, "SI").
     */
    public static function crossed(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $text = mb_strtoupper(trim((string) ($value ?? '')));

        return in_array($text, ['X', '✓', '✔', 'SI', 'SÍ', 'TRUE', '1'], true);
    }

    /**
     * The day of the round as Y-m-d, or null when the header has none or it is not a date.
     */
    public function roundDate(): ?string
    {
        return $this->fechaRecorrida !== null ? self::parseDate($this->fechaRecorrida) : null;
    }

    /**
     * Y-m-d, or the d/m/Y the sheet is written in. Null when it is not a real date.
     */
    public static function parseDate(string $raw): ?string
    {
        $raw = trim($raw);
        $candidates = [['Y-m-d', substr($raw, 0, 10)], ['d/m/Y', $raw], ['d-m-Y', $raw]];

        foreach ($candidates as [$format, $value]) {
            $date = \DateTimeImmutable::createFromFormat('!' . $format, $value);

            if ($date !== false && $date->format($format) === $value) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }

    private static function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
