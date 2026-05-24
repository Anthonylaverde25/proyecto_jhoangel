<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Core\Entities\ServiceOrderHistoryEntity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read ServiceOrderHistoryEntity $resource
 */
class ServiceOrderHistoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->resource->getId(),
            'company_id'       => $this->resource->getCompanyId(),
            'service_order_id' => $this->resource->getServiceOrderId(),
            'from_status'      => $this->resource->getFromStatus(),
            'to_status'        => $this->resource->getToStatus(),
            'action_user_id'   => $this->resource->getActionUserId(),
            'action_reason'    => $this->resource->getActionReason(),
            'action_metadata'  => $this->resource->getActionMetadata(),
            'created_at'       => $this->resource->getCreatedAt()?->format('Y-m-d H:i:s'),
        ];
    }
}
