<?php

declare(strict_types=1);

namespace App\Application\DTOs\ExitOrders;

/** "Emitir egreso": who buys, when, and which animals (egreso plan v5, 05 §3). */
final readonly class IssueExitOrderDTO
{
    /**
     * @param list<int> $caravanIds
     * @param array<int, float> $agreedPrices caravan id => $/kg agreed with the buyer (optional)
     */
    public function __construct(
        public int $companyId,
        public ?int $userId,
        public int $buyerPartnerId,
        public string $exitDate,
        public array $caravanIds,
        public ?string $responsable = null,
        public ?string $observations = null,
        public array $agreedPrices = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, int $companyId, ?int $userId): self
    {
        return new self(
            $companyId,
            $userId,
            (int) $data['buyer_partner_id'],
            (string) $data['exit_date'],
            array_map('intval', array_values($data['caravan_ids'] ?? [])),
            $data['responsable'] ?? null,
            $data['observations'] ?? null,
            collect($data['agreed_prices'] ?? [])
                ->mapWithKeys(fn (array $row) => [(int) $row['caravan_id'] => (float) $row['price_per_kg']])
                ->all(),
        );
    }
}
