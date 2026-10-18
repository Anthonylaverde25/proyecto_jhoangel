<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Core\Entities\FiscalDocumentEntity;
use App\Core\Entities\FiscalDocumentLineEntity;
use App\Core\Entities\FiscalDocumentTaxEntity;
use App\Core\Entities\StockIncidentEntity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A provider document. The list shows the header; the detail adds lines, taxes and the VAT summary
 * per rate, computed from the lines (DS16).
 *
 * @property-read FiscalDocumentEntity $resource
 */
class FiscalDocumentResource extends JsonResource
{
    private bool $detailed = false;

    /** @var list<StockIncidentEntity> */
    private array $incidents = [];

    /** @param list<StockIncidentEntity> $incidents */
    public function detailed(array $incidents = []): self
    {
        $this->detailed = true;
        $this->incidents = $incidents;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $document = $this->resource;
        $type = $document->getType();
        $data = [
            'id' => $document->getId(),
            'label' => $document->label(),
            'type' => FiscalCatalogResource::type($type),
            'provider' => ['id' => $document->getProviderId(), 'name' => $document->getProviderName()],
            'point_of_sale' => $document->getPointOfSale(),
            'number' => $document->getNumber(),
            'formatted_number' => FiscalDocumentEntity::formatNumber($document->getPointOfSale(), $document->getNumber(), $document->getReference()),
            'reference' => $document->getReference(),
            'issued_at' => $document->getIssuedAt(),
            'lines_amount' => $document->getLinesAmount(),
            'line_taxes_amount' => $document->getLineTaxesAmount(),
            'document_taxes_amount' => $document->getDocumentTaxesAmount(),
            'total_amount' => $document->getTotalAmount(),
            'declared_total' => $document->getDeclaredTotal(),
            'total_difference' => $document->totalDifference(),
            'lines_count' => count($document->getLines()),
            'status' => $document->getStatus()->value,
            'void_reason' => $document->getVoidReason(),
            'voided_at' => $document->getVoidedAt(),
            'related' => $document->getRelatedFiscalDocumentId() === null ? null
                : ['id' => $document->getRelatedFiscalDocumentId(), 'label' => $document->getRelatedLabel()],
            'stock_operation' => $document->getStockOperationId() === null ? null
                : ['id' => $document->getStockOperationId(), 'number' => $document->getStockOperationNumber()],
        ];
        if (!$this->detailed) {
            return $data;
        }

        return $data + [
            'notes' => $document->getNotes(),
            'created_by' => $document->getCreatedByName(),
            'created_at' => $document->getCreatedAt(),
            'lines' => array_map(fn (FiscalDocumentLineEntity $line) => [
                'id' => $line->id,
                'position' => $line->position,
                'item' => ['id' => $line->itemId, 'name' => $line->itemName, 'unit_symbol' => $line->itemUnitSymbol, 'track_stock' => $line->tracksStock],
                'input_quantity' => $line->inputQuantity,
                'input_unit_id' => $line->inputUnitId,
                'input_presentation_id' => $line->inputPresentationId,
                'input_label' => $line->inputLabel,
                'quantity' => $line->quantity,
                'unit_price' => $line->unitPrice,
                'line_amount' => $line->lineAmount,
                'non_recoverable_tax_amount' => $line->nonRecoverableTaxAmount,
                'cost_amount' => $line->costAmount,
                'unit_cost' => $line->unitCost,
                'batch' => $line->batchId === null ? null : ['id' => $line->batchId, 'name' => $line->batchName],
                'heads_treated' => $line->headsTreated,
                'taxes' => array_map(fn (FiscalDocumentTaxEntity $tax) => self::tax($tax), $line->taxes),
            ], $document->getLines()),
            'document_taxes' => array_map(fn (FiscalDocumentTaxEntity $tax) => self::tax($tax), $document->getDocumentTaxes()),
            'tax_summary' => self::summary($document),
            'incidents_raised' => array_map(fn (StockIncidentEntity $i) => StockIncidentResource::shape($i), $this->incidents),
        ];
    }

    /** @return array<string, mixed> */
    private static function tax(FiscalDocumentTaxEntity $tax): array
    {
        return [
            'tax_id' => $tax->taxId,
            'code' => $tax->taxCode,
            'name' => $tax->taxName,
            'tax_rate_id' => $tax->taxRateId,
            'rate_name' => $tax->rateName,
            'rate' => $tax->rate,
            'taxable_base' => $tax->taxableBase,
            'amount' => $tax->amount,
            'is_recoverable' => $tax->isRecoverable,
        ];
    }

    /**
     * One row per line tax and rate ("IVA 21 %", "Impuesto a los combustibles") plus one per document
     * tax, as the paper prints them.
     *
     * @return list<array{label: string, amount: string, is_recoverable: bool}>
     */
    private static function summary(FiscalDocumentEntity $document): array
    {
        $rows = [];
        foreach ($document->getLines() as $line) {
            foreach ($line->taxes as $tax) {
                $label = $tax->rateName !== null ? "{$tax->taxName} {$tax->rateName}" : $tax->taxName;
                $rows[$label] ??= ['label' => $label, 'amount' => '0.0000', 'is_recoverable' => $tax->isRecoverable];
                $rows[$label]['amount'] = bcadd($rows[$label]['amount'], $tax->amount, 4);
            }
        }
        foreach ($document->getDocumentTaxes() as $tax) {
            $rows[$tax->taxName] = ['label' => $tax->taxName, 'amount' => $tax->amount, 'is_recoverable' => $tax->isRecoverable];
        }

        return array_values($rows);
    }
}
