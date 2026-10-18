<?php

declare(strict_types=1);

namespace App\Application\DTOs\Stock;

/**
 * A provider document as typed from the paper.
 */
final readonly class FiscalDocumentDTO
{
    /**
     * @param list<FiscalLineDTO> $lines
     * @param list<TaxInputDTO> $documentTaxes
     */
    public function __construct(
        public int $providerId,
        public int $typeId,
        public ?int $pointOfSale,
        public ?int $number,
        public ?string $reference,
        public string $issuedAt,
        public ?string $declaredTotal,
        public ?int $relatedFiscalDocumentId,
        public ?string $notes,
        public string $idempotencyKey,
        public array $lines,
        public array $documentTaxes,
        public ?int $createdBy = null,
        /** Delivery notes only: whether the paper carries prices (DC14, RM2). */
        public ?bool $isValued = null,
        /** Invoices only: the delivery notes it is converted from (DC5). @var list<int> */
        public array $deliveryNoteIds = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, ?int $createdBy = null): self
    {
        $text = fn (string $key): ?string => isset($data[$key]) && trim((string) $data[$key]) !== '' ? trim((string) $data[$key]) : null;

        return new self(
            (int) $data['provider_id'],
            (int) $data['fiscal_document_type_id'],
            isset($data['point_of_sale']) ? (int) $data['point_of_sale'] : null,
            isset($data['number']) ? (int) $data['number'] : null,
            $text('reference'),
            (string) $data['issued_at'],
            $text('declared_total'),
            isset($data['related_fiscal_document_id']) ? (int) $data['related_fiscal_document_id'] : null,
            $text('notes'),
            (string) $data['idempotency_key'],
            array_map(fn (array $line) => FiscalLineDTO::fromArray($line), array_values($data['lines'] ?? [])),
            array_map(fn (array $tax) => TaxInputDTO::fromArray($tax), array_values($data['document_taxes'] ?? [])),
            $createdBy,
            isset($data['is_valued']) ? (bool) $data['is_valued'] : null,
            array_values(array_map('intval', $data['delivery_note_ids'] ?? [])),
        );
    }
}
