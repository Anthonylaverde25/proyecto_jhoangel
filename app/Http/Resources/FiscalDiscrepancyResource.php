<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Core\Entities\FiscalDiscrepancyEntity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A difference between a delivery note and its invoice (delivery C, DC7).
 *
 * @property-read FiscalDiscrepancyEntity $resource
 */
class FiscalDiscrepancyResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $d = $this->resource;

        return [
            'id' => $d->getId(),
            'type' => $d->getType()->value,
            'type_label' => $d->getType()->label(),
            'status' => $d->getStatus()->value,
            'invoice' => ['id' => $d->getInvoiceDocumentId(), 'label' => $d->getInvoiceLabel()],
            'delivery_note' => $d->getDeliveryNoteId() === null ? null : ['id' => $d->getDeliveryNoteId(), 'label' => $d->getDeliveryNoteLabel()],
            'item' => $d->getItemId() === null ? null : ['id' => $d->getItemId(), 'name' => $d->getItemName()],
            'expected_value' => $d->getExpectedValue(),
            'actual_value' => $d->getActualValue(),
            'detail' => $d->getDetail(),
            'resolution' => $d->getResolution(),
            'resolved_by' => $d->getResolvedByName(),
            'resolved_at' => $d->getResolvedAt(),
            'created_at' => $d->getCreatedAt(),
        ];
    }
}
