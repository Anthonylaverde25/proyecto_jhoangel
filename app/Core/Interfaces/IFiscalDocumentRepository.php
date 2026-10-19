<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

use App\Core\Entities\FiscalDocumentEntity;
use App\Core\ValueObjects\FiscalDocumentFilters;

/**
 * Provider documents of a company. Every method filters by company explicitly (D15, layer 2).
 * Nothing is updated or deleted except the void mark (DS20).
 */
interface IFiscalDocumentRepository
{
    public function findByIdempotencyKey(string $key, int $companyId): ?FiscalDocumentEntity;

    /** With lines, line taxes, document taxes and the stock operation it generated. */
    public function findById(int $id, int $companyId): ?FiscalDocumentEntity;

    /** DS21: a registered document with the same provider, type, point of sale and number. */
    public function findRegisteredDuplicate(int $companyId, int $providerId, int $typeId, int $pointOfSale, int $number): ?FiscalDocumentEntity;

    /** @return array{data: list<FiscalDocumentEntity>, total: int} headers only */
    public function search(FiscalDocumentFilters $filters, int $companyId): array;

    /**
     * Counts per kind and per status under the other filters, and the signed totals of the period
     * (delivery notes left out unless filtered: their invoice repeats them).
     *
     * @return array{by_kind: array<string, int>, by_status: array<string, int>, totals: array{count: int, net: string, taxes: string, total: string}}
     */
    public function summarize(FiscalDocumentFilters $filters, int $companyId): array;

    /** Inserts header, lines and taxes; returns the document with its ids. */
    public function create(FiscalDocumentEntity $document): FiscalDocumentEntity;

    public function markVoided(FiscalDocumentEntity $document, ?int $userId): void;

    /**
     * Delivery notes by id, locked (RF2), with their lines.
     *
     * @param list<int> $ids
     * @return list<FiscalDocumentEntity>
     */
    public function lockDeliveryNotes(array $ids, int $companyId): array;

    /**
     * Ties the delivery notes to the invoice they went into, or frees them (null) when it is voided.
     *
     * @param list<int> $ids
     */
    public function markInvoiced(array $ids, ?int $invoiceId, int $companyId): void;

    /** Delivery notes tied to an invoice. @return list<int> */
    public function deliveryNoteIdsOf(int $invoiceId, int $companyId): array;

    /**
     * Registered delivery notes without invoice (DC12), oldest first, with the state of their lots.
     *
     * @return list<array<string, mixed>>
     */
    public function pendingDeliveryNotes(?int $providerId, int $companyId): array;

    /**
     * Last registered invoice or ticket line of an item per provider (DS2).
     *
     * @return array<int, array{fiscal_document_id: int, label: string, issued_at: string, unit_price: string, input_label: string}>
     */
    public function lastPurchasesByProvider(int $itemId, int $companyId): array;

    /**
     * VAT rate of each item's last registered letter-A line, to preload it (DS18).
     *
     * @param list<int> $itemIds
     * @return array<int, int> item id => tax_rate_id
     */
    public function lastVatRateIds(array $itemIds, int $companyId): array;
}
