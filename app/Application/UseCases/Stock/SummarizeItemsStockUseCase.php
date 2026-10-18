<?php

declare(strict_types=1);

namespace App\Application\UseCases\Stock;

use App\Core\Interfaces\IFiscalDocumentRepository;
use App\Core\Interfaces\IItemStockRepository;

/**
 * What the item list adds from B2: each item's balance, value and stock alerts, and the VAT rate of
 * its last invoice to preload it on the next one (DS18).
 */
final class SummarizeItemsStockUseCase
{
    public function __construct(
        private readonly IItemStockRepository $stocks,
        private readonly IFiscalDocumentRepository $documents,
    ) {
    }

    /**
     * @param list<int> $itemIds
     * @return array{stock: array<int, array<string, mixed>>, vat: array<int, int>}
     */
    public function __invoke(array $itemIds, int $companyId): array
    {
        return [
            'stock' => $this->stocks->summaries($itemIds, $companyId),
            'vat' => $this->documents->lastVatRateIds($itemIds, $companyId),
        ];
    }
}
