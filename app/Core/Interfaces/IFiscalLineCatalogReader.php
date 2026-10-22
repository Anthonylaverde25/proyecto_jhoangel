<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

use App\Core\ValueObjects\FiscalCatalogItem;

/**
 * Read side of the line matching (Delivery G): the active catalog, the provider's items and the VAT
 * rates by code.
 */
interface IFiscalLineCatalogReader
{
    /** @return list<FiscalCatalogItem> active items, with their active presentations, in one query each. */
    public function activeItems(int $companyId): array;

    /** @return list<int> items linked to the provider in item_provider. */
    public function itemIdsOfProvider(int $providerId, int $companyId): array;

    /** @return array<string, int> VAT tax_rates in force, id by code. */
    public function vatRateIdsByCode(): array;
}
