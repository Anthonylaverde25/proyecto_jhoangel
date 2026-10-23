<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Application\Services\Stock\LedgerResult;
use App\Core\Entities\ItemDocumentEntity;
use App\Core\Entities\ItemDocumentLineEntity;
use App\Core\Entities\StockIncidentEntity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A stock operation with its lines and, in the detail, each line's movement.
 *
 * @property-read ItemDocumentEntity $resource
 */
class ItemDocumentResource extends JsonResource
{
    /** @var list<StockIncidentEntity> */
    private array $incidents = [];

    public static function fromLedger(LedgerResult $result): self
    {
        $resource = new self($result->document);
        $resource->incidents = $result->incidents;

        return $resource;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $document = $this->resource;

        return [
            'id' => $document->getId(),
            'number' => $document->getNumber(),
            'type' => $document->getType()->value,
            'type_label' => $document->getType()->label(),
            'occurred_at' => $document->getOccurredAt(),
            'warehouse' => ['id' => $document->getWarehouseId(), 'code' => $document->getWarehouseCode(), 'name' => $document->getWarehouseName()],
            // Semen S-A: where a semen entry came from (no debt nor invoice).
            'provider' => $document->getProviderId() === null ? null : ['id' => $document->getProviderId(), 'name' => $document->getProviderName()],
            'service_order' => $document->getServiceOrderId() === null ? null : ['id' => $document->getServiceOrderId(), 'code' => $document->getServiceOrderCode()],
            // Delivery D: the other half of a transfer.
            'paired' => $document->getCounterpart() === null ? null : [
                'id' => $document->getCounterpart()->id,
                'number' => $document->getCounterpart()->number,
                'type' => $document->getCounterpart()->type->value,
                'warehouse' => [
                    'id' => $document->getCounterpart()->warehouseId,
                    'code' => $document->getCounterpart()->warehouseCode,
                    'name' => $document->getCounterpart()->warehouseName,
                ],
            ],
            'fiscal_document' => $document->getFiscalDocumentId() === null ? null
                : ['id' => $document->getFiscalDocumentId(), 'label' => $document->getFiscalDocumentLabel()],
            'batch' => $document->getBatchId() === null ? null : ['id' => $document->getBatchId(), 'name' => $document->getBatchName()],
            'heads_treated' => $document->getHeadsTreated(),
            'reverses' => $document->getReversesDocumentId() === null ? null
                : ['id' => $document->getReversesDocumentId(), 'number' => $document->getReversesNumber()],
            'reversed_by' => $document->getReversedById() === null ? null
                : ['id' => $document->getReversedById(), 'number' => $document->getReversedByNumber()],
            'can_reverse' => $document->reversalRefusal() === null,
            'reversal_refusal' => $document->reversalRefusal(),
            'reason' => $document->getReason(),
            'notes' => $document->getNotes(),
            'total_cost' => $document->totalCost(),
            'created_by' => $document->getCreatedByName(),
            'created_at' => $document->getCreatedAt(),
            'lines' => array_map(fn (ItemDocumentLineEntity $line) => [
                'id' => $line->getId(),
                'position' => $line->getPosition(),
                'item' => ['id' => $line->getItemId(), 'name' => $line->getItemName(), 'unit_symbol' => $line->getItemUnitSymbol()],
                'semen_lot' => $line->getSemenLotId() === null ? null : ['id' => $line->getSemenLotId(), 'code' => $line->getSemenLotCode()],
                'input_quantity' => $line->getInputQuantity(),
                'input_label' => $line->getInputLabel(),
                'quantity' => $line->getQuantity(),
                'input_unit_price' => $line->getInputUnitPrice(),
                'unit_cost' => $line->getUnitCostOrNull(),
                'total_cost' => $line->getTotalCost(),
                // Delivery C: units without cost yet, and the share of a valuation no stock could take.
                'unvalued_quantity' => $line->getUnvaluedQuantity(),
                'unabsorbed_cost' => $line->getUnabsorbedCost(),
                'counted_quantity' => $line->getCountedQuantity(),
                'movement' => $line->getMovement() === null ? null : [
                    'id' => $line->getMovement()->id,
                    'direction' => $line->getMovement()->direction,
                    'quantity' => $line->getMovement()->quantity,
                    'balance_after' => $line->getMovement()->balanceAfter,
                    'average_cost_after' => $line->getMovement()->averageCostAfter,
                    'unvalued_balance_after' => $line->getMovement()->unvaluedBalanceAfter,
                ],
            ], $document->getLines()),
            'incidents_raised' => array_map(fn (StockIncidentEntity $i) => StockIncidentResource::shape($i), $this->incidents),
        ];
    }
}
