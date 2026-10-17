<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Core\ValueObjects\CageGroupWeighing;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read \App\Core\Entities\WeighingOrderEntity $resource
 */
class WeighingOrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $order = $this->resource;
        $average = $order->averageWeight();

        return [
            'id' => $order->getId(),
            'code' => $order->getCode(),
            'status' => $order->getStatus()->value,
            'status_label' => $order->getStatus()->label(),
            'kind' => $order->getKind()->value,
            'mode' => $order->getMode()->value,
            'weighing_type' => $order->getType()->value,
            'weighing_type_name' => $order->getType()->label(),
            'batch_id' => $order->getBatchId(),
            'batch_name' => $order->getBatchName(),
            'shrunk_condition' => $order->getShrunkCondition()->value,
            'shrink_pct' => $order->getShrinkPct(),
            'weighing_date' => $order->getWeighingDate(),
            'weighing_time' => $order->getWeighingTime(),
            'planned_head_count' => $order->getPlannedHeadCount(),
            'weighed_count' => $order->weighedCount(),
            'total_heads' => $order->totalHeads(),
            'total_net_weight' => round($order->totalNetWeight(), 2),
            'average_weight' => $average !== null ? round($average, 2) : null,
            'entry' => $order->getMeasure()?->entry->value,
            'groups' => array_map(static fn (CageGroupWeighing $g): array => [
                'sequence' => $g->sequence,
                'head_count' => $g->headCount,
                'gross_weight' => $g->grossWeight,
                'tare_weight' => $g->tareWeight,
                'net_weight' => round($g->netWeight(), 2),
                'average_weight' => round($g->averageWeight(), 2),
            ], $order->getMeasure()?->loads ?? []),
            'responsable' => $order->getResponsable(),
            'observations' => $order->getObservations(),
            'created_at' => $order->getCreatedAt()?->format(DATE_ATOM),
        ];
    }
}
