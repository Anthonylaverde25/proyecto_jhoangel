<?php

declare(strict_types=1);

namespace App\Core\ValueObjects;

use App\Core\Enums\FiscalDocumentKind;
use App\Core\Enums\FiscalDocumentStatus;

/**
 * Filters of the provider-document register.
 */
final readonly class FiscalDocumentFilters
{
    public function __construct(
        public ?int $providerId = null,
        public ?FiscalDocumentKind $kind = null,
        public FiscalDocumentStatus $status = FiscalDocumentStatus::REGISTERED,
        public ?string $from = null,
        public ?string $to = null,
        /** Matches the provider's name or CUIT, or the document number. */
        public ?string $search = null,
        public int $page = 1,
        public int $perPage = 25,
        /** Documents with at least one line of this item. */
        public ?int $itemId = null,
    ) {
    }

    /** @param array<string, mixed> $query */
    public static function fromArray(array $query): self
    {
        $search = isset($query['search']) ? trim((string) $query['search']) : '';

        return new self(
            isset($query['provider_id']) && (int) $query['provider_id'] > 0 ? (int) $query['provider_id'] : null,
            isset($query['kind']) ? FiscalDocumentKind::tryFrom((string) $query['kind']) : null,
            FiscalDocumentStatus::tryFrom((string) ($query['status'] ?? '')) ?? FiscalDocumentStatus::REGISTERED,
            self::date($query['from'] ?? null),
            self::date($query['to'] ?? null),
            $search === '' ? null : $search,
            max(1, (int) ($query['page'] ?? 1)),
            min(100, max(1, (int) ($query['per_page'] ?? 25))),
            isset($query['item_id']) && (int) $query['item_id'] > 0 ? (int) $query['item_id'] : null,
        );
    }

    private static function date(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }
}
