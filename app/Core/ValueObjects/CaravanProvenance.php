<?php

declare(strict_types=1);

namespace App\Core\ValueObjects;

final readonly class CaravanProvenance
{
    public function __construct(
        public ?string $originRenspa = null,
        public ?string $originBatchName = null,
        public ?int $originProviderId = null,
        public ?string $dteNumber = null,
        public ?string $auctionName = null,
        public ?float $purchaseWeight = null,
        public ?float $purchasePricePerKg = null,
        public ?string $assignedToOwnBatchAt = null,
        public array $extraData = []
    ) {
    }

    public function toArray(): array
    {
        return array_filter([
            'origin_renspa' => $this->originRenspa,
            'origin_batch_name' => $this->originBatchName,
            'origin_provider_id' => $this->originProviderId,
            'dte_number' => $this->dteNumber,
            'auction_name' => $this->auctionName,
            'purchase_weight' => $this->purchaseWeight,
            'purchase_price_per_kg' => $this->purchasePricePerKg,
            'assigned_to_own_batch_at' => $this->assignedToOwnBatchAt,
            'extra_data' => $this->extraData,
        ], fn ($value) => $value !== null && $value !== []);
    }

    public static function fromArray(?array $data): ?self
    {
        if (empty($data)) {
            return null;
        }

        return new self(
            originRenspa: isset($data['origin_renspa']) ? (string) $data['origin_renspa'] : null,
            originBatchName: isset($data['origin_batch_name']) ? (string) $data['origin_batch_name'] : null,
            originProviderId: isset($data['origin_provider_id']) ? (int) $data['origin_provider_id'] : null,
            dteNumber: isset($data['dte_number']) ? (string) $data['dte_number'] : null,
            auctionName: isset($data['auction_name']) ? (string) $data['auction_name'] : null,
            purchaseWeight: isset($data['purchase_weight']) ? (float) $data['purchase_weight'] : null,
            purchasePricePerKg: isset($data['purchase_price_per_kg']) ? (float) $data['purchase_price_per_kg'] : null,
            assignedToOwnBatchAt: isset($data['assigned_to_own_batch_at']) ? (string) $data['assigned_to_own_batch_at'] : null,
            extraData: (array) ($data['extra_data'] ?? [])
        );
    }
}
