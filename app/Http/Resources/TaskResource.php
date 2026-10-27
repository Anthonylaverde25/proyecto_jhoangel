<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Core\Entities\TaskEntity;
use App\Core\ValueObjects\TaskProviderRef;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TaskEntity
 */
class TaskResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var TaskEntity $this */
        return [
            'id' => $this->getId(),
            'description' => $this->getDescription(),
            'urgency' => $this->getUrgency()->value,
            'providers' => array_map(
                static fn (TaskProviderRef $provider): array => ['id' => $provider->id, 'name' => $provider->name],
                $this->getProviders()
            ),
            'created_at' => $this->getCreatedAt()?->format(DATE_ATOM),
            'updated_at' => $this->getUpdatedAt()?->format(DATE_ATOM),
        ];
    }
}
