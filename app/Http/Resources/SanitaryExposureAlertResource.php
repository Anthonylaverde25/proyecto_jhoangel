<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Core\Entities\ExposedFemaleEntry;
use App\Core\Entities\SanitaryExposureAlertEntity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property SanitaryExposureAlertEntity $resource
 */
class SanitaryExposureAlertResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $alert = $this->resource;

        return [
            'id' => $alert->id,
            'bull' => ['id' => $alert->bullId, 'identification' => $alert->bullTag],
            'bull_active_order' => $alert->bullActiveOrder,
            'pathogen_code' => $alert->pathogenCode,
            'pathogen_name' => $alert->pathogenLabel(),
            'detected_on' => $alert->detectedOn->format('Y-m-d'),
            'risk_window_from' => $alert->riskWindowFrom->format('Y-m-d'),
            'detail' => $alert->detail,
            'status' => $alert->status,
            'resolution' => $alert->resolution,
            'resolved_at' => $alert->resolvedAt?->format(DATE_ATOM),
            'resolved_by' => $alert->resolvedByName,
            'females_count' => $alert->femalesCount,
            'reviewed_count' => $alert->reviewedCount,
            'females' => array_map(fn (ExposedFemaleEntry $f) => [
                'female_id' => $f->femaleId,
                'identification' => $f->femaleTag,
                'current_batch' => $f->currentBatchId !== null ? ['id' => $f->currentBatchId, 'name' => $f->currentBatchName] : null,
                'is_pregnant' => $f->isPregnant,
                'service_order' => ['id' => $f->serviceOrderId, 'code' => $f->serviceOrderCode],
                'overlap_from' => $f->overlapFrom->format('Y-m-d'),
                'overlap_until' => $f->overlapUntil?->format('Y-m-d'),
                'reviewed_at' => $f->reviewedAt?->format(DATE_ATOM),
                'review_note' => $f->reviewNote,
            ], $alert->females),
        ];
    }
}
