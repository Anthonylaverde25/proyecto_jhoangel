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

    /** @var array{open: int, resolved: int, cancelled: int}|null */
    private ?array $discrepancies = null;

    /**
     * @param list<StockIncidentEntity> $incidents
     * @param array{open: int, resolved: int, cancelled: int}|null $discrepancies differences with delivery notes (DC7)
     */
    public function detailed(array $incidents = [], ?array $discrepancies = null): self
    {
        $this->detailed = true;
        $this->incidents = $incidents;
        $this->discrepancies = $discrepancies;

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
            'provider' => [
                'id' => $document->getProviderId(),
                'name' => $document->getProviderName(),
                'cuit' => $document->getProviderCuit(),
                'location' => $document->getProviderLocation(),
            ],
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
            // Delivery C: whether a delivery note is priced, where it went, and what an invoice came from.
            'is_valued' => $document->isValued(),
            'delivery_note_status' => self::deliveryNoteStatus($document),
            'invoiced_by' => $document->getInvoicedByDocumentId() === null ? null
                : ['id' => $document->getInvoicedByDocumentId(), 'label' => $document->getInvoicedByLabel()],
            'delivery_notes' => $document->getDeliveryNotes(),
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
                'item' => ['id' => $line->itemId, 'code' => $line->itemCode, 'name' => $line->itemName, 'unit_symbol' => $line->itemUnitSymbol, 'track_stock' => $line->tracksStock],
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
                'delivery_note_line_id' => $line->deliveryNoteLineId,
                'delivery_note_label' => $line->deliveryNoteLabel,
                'unvalued_lot' => $line->unvaluedLot === null ? null : $line->unvaluedLot + [
                    'consumed_quantity' => bcsub($line->unvaluedLot['quantity'], $line->unvaluedLot['remaining_quantity'], 4),
                ],
            ], $document->getLines()),
            'document_taxes' => array_map(fn (FiscalDocumentTaxEntity $tax) => self::tax($tax), $document->getDocumentTaxes()),
            'tax_summary' => self::summary($document),
            'incidents_raised' => array_map(fn (StockIncidentEntity $i) => StockIncidentResource::shape($i), $this->incidents),
            'discrepancies' => $this->discrepancies,
        ];
    }

    /** PENDING_INVOICE, INVOICED or VOIDED for a delivery note (DC10); null for any other document. */
    private static function deliveryNoteStatus(FiscalDocumentEntity $document): ?array
    {
        if (!$document->isDeliveryNote()) {
            return null;
        }

        return match (true) {
            $document->isVoided() => ['code' => 'VOIDED', 'label' => 'Anulado'],
            $document->getInvoicedByDocumentId() !== null => ['code' => 'INVOICED', 'label' => 'Facturado'],
            default => ['code' => 'PENDING_INVOICE', 'label' => 'Pendiente de factura'],
        };
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
