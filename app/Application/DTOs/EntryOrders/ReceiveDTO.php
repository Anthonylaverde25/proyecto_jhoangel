<?php

declare(strict_types=1);

namespace App\Application\DTOs\EntryOrders;

use App\Core\Enums\ReceptionMethod;

/**
 * A reception of caravans of an entry order. By hand (MANUAL) it is done on one DTE, or on every
 * DTE of the order when none is given ("Recibir todo"), and may declare caravans that will not
 * arrive; at the chute (CHUTE) it brings the caravans read, of any
 * DTE of the order, identified by their number. From a scanned ING-03 sheet (SHEET) it is like
 * the manual one, names the sheet and the pages it covers, and may bring animals whose caravan no
 * DTE lists (`unlisted`). Any line may bring the body condition seen at arrival (official scale 1
 * to 5).
 */
final readonly class ReceiveDTO
{
    /**
     * @param list<array{caravan_id: ?int, identification: ?string, weight: ?float, body_condition: ?float}> $received
     * @param list<int> $missing
     * @param list<int> $pages pages of the receipt sheet this reception covers
     * @param list<array{identification: string, sex: ?string, breed: ?string, coat: ?string, weight: ?float, body_condition: ?float}> $unlisted
     */
    public function __construct(
        public ReceptionMethod $method,
        public string $receivedAt,
        public ?int $dteId,
        public array $received,
        public array $missing,
        public ?string $reason,
        public ?string $dteNumber = null,
        public ?int $receiptSheetId = null,
        public array $pages = [],
        public array $unlisted = []
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $method = ReceptionMethod::from(strtoupper((string) $data['method']));
        $reason = trim((string) ($data['reason'] ?? ''));

        return new self(
            method: $method,
            receivedAt: (string) $data['received_at'],
            dteId: isset($data['dte_id']) && $data['dte_id'] !== '' ? (int) $data['dte_id'] : null,
            received: array_map(fn (array $row) => [
                'caravan_id' => isset($row['caravan_id']) && $row['caravan_id'] !== '' ? (int) $row['caravan_id'] : null,
                'identification' => isset($row['identification']) && trim((string) $row['identification']) !== ''
                    ? trim((string) $row['identification'])
                    : null,
                'weight' => isset($row['weight']) && $row['weight'] !== '' ? (float) $row['weight'] : null,
                'body_condition' => self::bodyCondition($row),
            ], array_values($data['received'] ?? [])),
            missing: array_map('intval', array_values($data['missing'] ?? [])),
            reason: $reason !== '' ? $reason : null,
            receiptSheetId: isset($data['receipt_sheet_id']) && $data['receipt_sheet_id'] !== '' ? (int) $data['receipt_sheet_id'] : null,
            pages: array_map('intval', array_values($data['pages'] ?? [])),
            unlisted: array_values(array_map(fn (array $row) => [
                'identification' => trim((string) ($row['identification'] ?? '')),
                'sex' => isset($row['sex']) && trim((string) $row['sex']) !== '' ? strtoupper(trim((string) $row['sex'])) : null,
                'breed' => isset($row['breed']) && trim((string) $row['breed']) !== '' ? trim((string) $row['breed']) : null,
                'coat' => isset($row['coat']) && trim((string) $row['coat']) !== '' ? trim((string) $row['coat']) : null,
                'weight' => isset($row['weight']) && $row['weight'] !== '' ? (float) $row['weight'] : null,
                'body_condition' => self::bodyCondition($row),
            ], $data['unlisted'] ?? []))
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function bodyCondition(array $row): ?float
    {
        return isset($row['body_condition']) && $row['body_condition'] !== '' ? (float) $row['body_condition'] : null;
    }

    /**
     * Everything a DTE listed, received on one day: what "Registrar ingreso" does, with the DTE
     * and the animals in hand at once.
     *
     * @param list<array{identification: string, weight: ?float}> $received
     */
    public static function wholeDte(string $dteNumber, string $receivedAt, array $received): self
    {
        return new self(
            method: ReceptionMethod::MANUAL,
            receivedAt: $receivedAt,
            dteId: null,
            received: array_map(fn (array $row) => ['caravan_id' => null, 'body_condition' => null, ...$row], $received),
            missing: [],
            reason: null,
            dteNumber: $dteNumber
        );
    }
}
