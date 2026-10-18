<?php

declare(strict_types=1);

namespace App\Application\UseCases\Stock;

use App\Core\Interfaces\IFiscalDocumentRepository;
use App\Core\Interfaces\IItemUsageReader;

/**
 * What the item file adds from B2: whether the item already has movements (its unit and behaviour
 * are then frozen) and the last purchase to each provider (DS2).
 */
final class DescribeItemUsageUseCase
{
    public function __construct(
        private readonly IItemUsageReader $usage,
        private readonly IFiscalDocumentRepository $documents,
    ) {
    }

    /** @return array{has_movements: bool, last_purchases: array<int, array<string, mixed>>} */
    public function __invoke(int $itemId, int $companyId): array
    {
        return [
            'has_movements' => $this->usage->itemHasLines($itemId, $companyId),
            'last_purchases' => $this->documents->lastPurchasesByProvider($itemId, $companyId),
        ];
    }
}
