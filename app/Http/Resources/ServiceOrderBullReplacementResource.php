<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Core\Enums\BullReplacementReason;
use App\Models\ServiceOrderBullReplacement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read ServiceOrderBullReplacement $resource
 */
class ServiceOrderBullReplacementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $reasonEnum = BullReplacementReason::tryFrom((string)$this->resource->reason);

        return [
            'id'                          => $this->resource->id,
            'company_id'                  => $this->resource->company_id,
            'service_order_id'            => $this->resource->service_order_id,
            'retired_male_caravan_id'     => $this->resource->retired_male_caravan_id,
            'replacement_male_caravan_id' => $this->resource->replacement_male_caravan_id,
            'replacement_date'            => $this->resource->replacement_date?->format('Y-m-d H:i:s'),
            'reason'                      => $this->resource->reason,
            'reason_label'                => $reasonEnum?->label() ?? $this->resource->reason,
            'destination_batch_id'        => $this->resource->destination_batch_id,
            'notes'                       => $this->resource->notes,
            'user_id'                     => $this->resource->user_id,
            'retired_male'                => $this->resource->retiredMaleCaravan ? [
                'id'             => $this->resource->retiredMaleCaravan->id,
                'identification' => $this->resource->retiredMaleCaravan->identification,
                'category'       => $this->resource->retiredMaleCaravan->category_name ?? $this->resource->retiredMaleCaravan->category,
                'breed'          => $this->resource->retiredMaleCaravan->breed?->name,
                'current_weight' => $this->resource->retiredMaleCaravan->currentWeight?->weight ?? $this->resource->retiredMaleCaravan->current_weight,
            ] : null,
            'replacement_male'            => $this->resource->replacementMaleCaravan ? [
                'id'                    => $this->resource->replacementMaleCaravan->id,
                'identification'        => $this->resource->replacementMaleCaravan->identification,
                'category'              => $this->resource->replacementMaleCaravan->category_name ?? $this->resource->replacementMaleCaravan->category,
                'breed'                 => $this->resource->replacementMaleCaravan->breed?->name,
                'current_weight'        => $this->resource->replacementMaleCaravan->currentWeight?->weight ?? $this->resource->replacementMaleCaravan->current_weight,
                'scrotal_circumference' => $this->resource->replacementMaleCaravan->bullHealthEvaluation?->scrotal_circumference_cm,
            ] : null,
            'destination_batch'           => $this->resource->destinationBatch ? [
                'id'   => $this->resource->destinationBatch->id,
                'name' => $this->resource->destinationBatch->name,
            ] : null,
            'user_name'                   => $this->resource->user?->name,
            'created_at'                  => $this->resource->created_at?->format('Y-m-d H:i:s'),
        ];
    }
}
