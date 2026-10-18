<?php

declare(strict_types=1);

namespace App\Core\ValueObjects;

use App\Core\Enums\DiscrepancyStatus;

/** What the list of delivery-note discrepancies is filtered by (delivery C). */
final readonly class DiscrepancyFilter
{
    public function __construct(
        public ?DiscrepancyStatus $status = null,
        public ?int $providerId = null,
        public ?int $invoiceId = null,
        public ?int $deliveryNoteId = null,
    ) {
    }

    /** @param array<string, mixed> $query */
    public static function fromArray(array $query): self
    {
        $int = fn (string $key): ?int => isset($query[$key]) && $query[$key] !== '' ? (int) $query[$key] : null;

        return new self(
            isset($query['status']) ? DiscrepancyStatus::tryFrom((string) $query['status']) : null,
            $int('provider_id'),
            $int('invoice_id'),
            $int('delivery_note_id'),
        );
    }
}
