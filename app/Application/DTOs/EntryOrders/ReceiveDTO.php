<?php

declare(strict_types=1);

namespace App\Application\DTOs\EntryOrders;

use App\Core\Enums\ArrivalFinding;
use App\Core\Enums\ReceptionMethod;

/**
 * A reception of the animals of one DTE of an entry order: one line per caravan written down, by
 * hand (MANUAL) or from a scanned ING-03 sheet (SHEET, which names the sheet and the pages it
 * covers). Head of the DTE left out stay in transit unless `missingHeadCount` declares them as
 * never arriving, with a reason. A manual reception may instead confirm `receivedHeadCount`, the
 * head that arrived: that closes the DTE, and its caravans become optional. Any line may bring the weight and the body condition seen at
 * arrival (official scale 1 to 5), and what the chute saw on the animal (an injured eye, ear or limb).
 * Breed and category come by position, or as written on a sheet printed with words (`*_text`).
 */
final readonly class ReceiveDTO
{
    /**
     * @param list<array{caravana: string, sex: ?string, breed_position: ?int, category_position: ?int, weight: ?float, body_condition: ?float, breed_text: ?string, color_text: ?string, category_text: ?string, arrival_findings: list<ArrivalFinding>}> $animals
     * @param list<int> $pages pages of the receipt sheet this reception covers
     */
    public function __construct(
        public ReceptionMethod $method,
        public string $receivedAt,
        public ?int $dteId,
        public array $animals,
        public int $missingHeadCount = 0,
        public ?string $reason = null,
        public ?string $dteNumber = null,
        public ?int $receiptSheetId = null,
        public array $pages = [],
        public ?int $receivedHeadCount = null,
        public ?string $triNumber = null
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $reason = trim((string) ($data['reason'] ?? ''));

        return new self(
            method: ReceptionMethod::from(strtoupper((string) $data['method'])),
            receivedAt: (string) $data['received_at'],
            dteId: isset($data['dte_id']) && $data['dte_id'] !== '' ? (int) $data['dte_id'] : null,
            animals: self::animals($data['animals'] ?? []),
            missingHeadCount: (int) ($data['missing_head_count'] ?? 0),
            reason: $reason !== '' ? $reason : null,
            receiptSheetId: isset($data['receipt_sheet_id']) && $data['receipt_sheet_id'] !== '' ? (int) $data['receipt_sheet_id'] : null,
            pages: array_map('intval', array_values($data['pages'] ?? [])),
            receivedHeadCount: isset($data['received_head_count']) && $data['received_head_count'] !== '' ? (int) $data['received_head_count'] : null,
            triNumber: isset($data['tri_number']) && trim((string) $data['tri_number']) !== '' ? trim((string) $data['tri_number']) : null
        );
    }

    /**
     * Every animal of a DTE received on one day: what "Registrar ingreso" does, with the DTE and
     * the animals in hand at once.
     *
     * @param array<int, array<string, mixed>> $animals
     */
    public static function wholeDte(string $dteNumber, string $receivedAt, array $animals): self
    {
        return new self(
            method: ReceptionMethod::MANUAL,
            receivedAt: $receivedAt,
            dteId: null,
            animals: self::animals($animals),
            dteNumber: $dteNumber
        );
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return list<array{caravana: string, sex: ?string, breed_position: ?int, category_position: ?int, weight: ?float, body_condition: ?float, breed_text: ?string, color_text: ?string, category_text: ?string, arrival_findings: list<ArrivalFinding>}>
     */
    private static function animals(array $rows): array
    {
        $filled = fn (array $row, string $key): bool => isset($row[$key]) && trim((string) $row[$key]) !== '';
        $text = fn (array $row, string $key): ?string => $filled($row, $key) ? trim((string) $row[$key]) : null;

        return array_map(fn (array $row) => [
            'caravana' => trim((string) ($row['caravana'] ?? '')),
            'sex' => $filled($row, 'sex') ? strtoupper(trim((string) $row['sex'])) : null,
            'breed_position' => $filled($row, 'breed_position') ? (int) $row['breed_position'] : null,
            'category_position' => $filled($row, 'category_position') ? (int) $row['category_position'] : null,
            'weight' => $filled($row, 'weight') ? (float) $row['weight'] : null,
            'body_condition' => $filled($row, 'body_condition') ? (float) $row['body_condition'] : null,
            'breed_text' => $text($row, 'breed_text'),
            'color_text' => $text($row, 'color_text'),
            'category_text' => $text($row, 'category_text'),
            'arrival_findings' => self::findings($row['arrival_findings'] ?? []),
        ], array_values($rows));
    }

    /**
     * Each finding once, whatever the order or repetition it came in.
     *
     * @param mixed $codes
     * @return list<ArrivalFinding>
     */
    private static function findings(mixed $codes): array
    {
        $findings = [];

        foreach (is_array($codes) ? $codes : [] as $code) {
            $finding = ArrivalFinding::tryFrom(strtoupper(trim((string) $code)));

            if ($finding !== null) {
                $findings[$finding->value] = $finding;
            }
        }

        return array_values($findings);
    }
}
