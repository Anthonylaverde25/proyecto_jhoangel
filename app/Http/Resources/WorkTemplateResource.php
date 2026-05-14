<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Core\Entities\WorkTemplateEntity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read WorkTemplateEntity $resource
 */
class WorkTemplateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->getId(),
            'title' => $this->resource->getTitle(),
            'description' => $this->resource->getDescription(),
            'type_id' => $this->resource->getTypeId(),
            'type_name' => $this->resource->getTypeName(),
            'type_color' => $this->resource->getTypeColor(),
            'type_icon' => $this->resource->getTypeIcon(),
            'status' => $this->resource->getStatus(),
            'schema_definition' => $this->resource->getSchemaDefinition(),
            'created_at' => $this->resource->getCreatedAt()?->format('Y-m-d H:i:s'),
        ];
    }
}
