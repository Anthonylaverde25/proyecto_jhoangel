<?php

declare(strict_types=1);

namespace App\Core\Entities;

/**
 * A line of a provider document, as on paper and converted to the item's unit. Its cost is its
 * amount plus its non-recoverable taxes (DS5, DS17). Services and non-stock articles carry the batch
 * that received them (D22).
 */
final readonly class FiscalDocumentLineEntity
{
    /** @param list<FiscalDocumentTaxEntity> $taxes */
    public function __construct(
        public int $position,
        public int $itemId,
        public string $inputQuantity,
        public ?int $inputUnitId,
        public ?int $inputPresentationId,
        public string $quantity,
        public string $unitPrice,
        public string $lineAmount,
        public string $nonRecoverableTaxAmount,
        public string $costAmount,
        public string $unitCost,
        public ?int $batchId,
        public ?int $headsTreated,
        public array $taxes,
        /** Whether the item counts in a warehouse: its stock operation takes only these lines. */
        public bool $tracksStock,
        public ?int $id = null,
        public ?string $itemName = null,
        public ?string $itemUnitSymbol = null,
        public ?string $inputLabel = null,
        public ?string $batchName = null,
    ) {
    }

    public function vat(): ?FiscalDocumentTaxEntity
    {
        foreach ($this->taxes as $tax) {
            if ($tax->taxCode === TaxEntity::VAT) {
                return $tax;
            }
        }

        return null;
    }
}
