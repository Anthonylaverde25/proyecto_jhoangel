<?php

declare(strict_types=1);

namespace App\Application\DTOs\Inseminations;

/** One row of the insemination sheet (semen S-B, IA6). Without lot: "Sin identificar" (IA8). */
final readonly class InseminationRowDTO
{
    public function __construct(
        public int $femaleCaravanId,
        public string $inseminatedOn,
        public int $itemId,
        public ?int $semenLotId,
        public int $strawsUsed,
        public int $warehouseId,
        public ?string $notes = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $notes = isset($data['notes']) ? trim((string) $data['notes']) : '';

        return new self(
            (int) $data['female_caravan_id'],
            substr((string) $data['inseminated_on'], 0, 10),
            (int) $data['item_id'],
            isset($data['semen_lot_id']) ? (int) $data['semen_lot_id'] : null,
            (int) ($data['straws_used'] ?? 1),
            (int) $data['warehouse_id'],
            $notes === '' ? null : $notes,
        );
    }
}
