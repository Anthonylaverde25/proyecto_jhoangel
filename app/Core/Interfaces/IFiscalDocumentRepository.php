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

    /** Inserts header, lines and taxes; returns the document with its ids. */
    public function create(FiscalDocumentEntity $document): FiscalDocumentEntity;

    public function markVoided(FiscalDocumentEntity $document, ?int $userId): void;

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
