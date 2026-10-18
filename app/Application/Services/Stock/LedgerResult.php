<?php

declare(strict_types=1);

namespace App\Application\Services\Stock;

use App\Core\Entities\ItemDocumentEntity;
use App\Core\Entities\StockIncidentEntity;

/**
 * A recorded stock operation and the notices its movements opened, so the screen can warn about
 * them right away. $replayed: found by its idempotency key instead of recorded now (DS8).
 */
final readonly class LedgerResult
{
    /** @param list<StockIncidentEntity> $incidents */
    public function __construct(
        public ItemDocumentEntity $document,
        public array $incidents = [],
        public bool $replayed = false,
    ) {
    }
}
