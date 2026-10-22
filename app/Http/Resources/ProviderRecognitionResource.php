<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Application\DTOs\ProviderRecognition;
use App\Application\DTOs\ResolvedFiscalLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read ProviderRecognition $resource
 */
class ProviderRecognitionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $recognition = $this->resource;
        $read = $recognition->read;

        return [
            'status' => $recognition->status,
            'reason' => $recognition->reason,
            'provider' => $recognition->provider !== null ? (new ProviderResource($recognition->provider))->resolve($request) : null,
            'issuer' => [
                'cuit' => $read->issuerCuit,
                'source' => $read->issuerCuitSource,
                'discrepancy_cuit' => $read->discrepancyCuit,
                'name' => $read->issuerName,
                'legal_name' => $read->issuerLegalName,
                'address' => $read->issuerAddress,
                'vat_condition' => $read->issuerVatCondition,
            ],
            'suggested_cuit' => $recognition->suggestedCuit,
            'receiver' => $read->receiverCuit !== null
                ? ['cuit' => $read->receiverCuit, 'source' => $read->receiverCuitSource]
                : null,
            'receiver_mismatch' => $recognition->receiverMismatch,
            'company_cuit' => $recognition->companyCuit,
            'company_cuit_missing' => $recognition->companyCuitMissing(),
            'lines' => array_map(fn (ResolvedFiscalLine $line) => [
                'position' => $line->read->position,
                'code' => $line->read->code,
                'description' => $line->read->description,
                'quantity' => $line->read->quantity,
                'unit_price' => $line->read->unitPrice,
                'vat_rate_code' => $line->read->vatRateCode,
                'vat_tax_rate_id' => $line->vatTaxRateId,
                'resolution' => [
                    'source' => $line->source,
                    'level' => $line->level,
                    'item_id' => $line->itemId,
                    'reason' => $line->reason,
                    'suggested_item_id' => $line->suggestedItemId,
                    'note' => $line->note,
                ],
            ], $recognition->lines),
            'qr' => $read->qr,
            'vision_error' => $read->visionError,
            'model' => $read->model,
            'latency_ms' => $read->latencyMs,
        ];
    }
}
