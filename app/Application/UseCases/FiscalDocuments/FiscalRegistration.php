<?php

declare(strict_types=1);

namespace App\Application\UseCases\FiscalDocuments;

use App\Core\Entities\FiscalDocumentEntity;
use App\Core\Entities\StockIncidentEntity;

/**
 * A registered provider document, the stock notices its operation opened, and whether it was
 * created now or found by its idempotency key (DS8).
 */
final readonly class FiscalRegistration
{
    /** @param list<StockIncidentEntity> $incidents */
    public function __construct(
        public FiscalDocumentEntity $document,
        public array $incidents,
        public bool $created,
    ) {
    }
}
