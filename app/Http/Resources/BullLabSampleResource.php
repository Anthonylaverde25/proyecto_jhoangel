<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\BullLabSample;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BullLabSample
 */
class BullLabSampleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'caravan_id' => $this->caravan_id,
            'evaluation_id' => $this->evaluation_id,
            'sample_type' => $this->sample_type,
            'sample_round' => $this->sample_round,
            'sample_date' => $this->sample_date?->format('Y-m-d'),
            'tube_number' => $this->tube_number,
            'status' => $this->status,
            'protocol_number' => $this->protocol_number,
            'result_date' => $this->result_date?->format('Y-m-d'),
            'pathogen_id' => $this->pathogen_id,
            'pathogen_name' => $this->pathogen?->name,
            'notes' => $this->notes,
        ];
    }
}
