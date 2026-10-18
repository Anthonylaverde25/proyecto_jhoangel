<?php

declare(strict_types=1);

namespace App\Application\Services\Stock;

use App\Core\Entities\FiscalDocumentLineEntity;
use App\Core\Entities\UnvaluedLotEntity;

/**
 * What an invoice line values (delivery C, model 2.3): the lot its delivery-note line left unvalued,
 * or the non-recoverable taxes a valued delivery note could not carry (Q3).
 */
final readonly class ValuationEntry
{
    public function __construct(
        public FiscalDocumentLineEntity $invoiceLine,
        public ?UnvaluedLotEntity $lot = null,
        public ?string $taxAmount = null,
    ) {
    }
}
