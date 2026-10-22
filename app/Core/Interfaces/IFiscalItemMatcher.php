<?php

declare(strict_types=1);

namespace App\Core\Interfaces;

use App\Core\Exceptions\FiscalReaderUnavailableException;
use App\Core\ValueObjects\FiscalCatalogItem;
use App\Core\ValueObjects\FiscalItemMatch;
use App\Core\ValueObjects\FiscalLineRead;

/**
 * Matches the lines read from a provider document to the company's catalog by meaning (the AI agent).
 */
interface IFiscalItemMatcher
{
    /**
     * @param list<FiscalLineRead> $lines
     * @param list<FiscalCatalogItem> $catalog
     * @param list<int> $providerItemIds Items the company buys from the document's provider.
     * @return list<FiscalItemMatch>
     *
     * @throws FiscalReaderUnavailableException
     */
    public function match(array $lines, array $catalog, array $providerItemIds, int $companyId): array;
}
