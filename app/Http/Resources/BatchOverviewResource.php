<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Core\ValueObjects\BatchAlert;
use App\Core\ValueObjects\BatchOverview;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property BatchOverview $resource
 */
class BatchOverviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $overview = $this->resource;

        return [
            'batch_id'             => $overview->batchId,
            'sex_composition'      => $overview->sexComposition,
            'category_composition' => $overview->categoryComposition,
            'last_weighing_date'   => $overview->lastWeighingDate?->format('Y-m-d'),
            'reproductive'         => $overview->reproductive,
            'alerts'               => array_map(fn (BatchAlert $alert) => [
                'type'     => $alert->type,
                'severity' => $alert->severity,
                'message'  => $alert->message,
                'count'    => $alert->count,
                'link'     => $alert->linkKind !== null
                    ? ['kind' => $alert->linkKind, 'id' => $alert->linkId, 'code' => $alert->linkCode]
                    : null,
            ], $overview->alerts),
        ];
    }
}
