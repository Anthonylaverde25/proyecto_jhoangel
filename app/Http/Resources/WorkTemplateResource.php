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
            'category' => $this->resource->getCategory(),
            'status' => $this->resource->getStatus(),
            'code' => $this->resource->getCode(),
            'schema_definition' => $this->resource->getSchemaDefinition(),
            'created_at' => $this->resource->getCreatedAt()?->format('Y-m-d H:i:s'),
        ];
    }
}
