<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Core\Entities\UserEntity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read UserEntity $resource
 */
class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->getId(),
            'name' => $this->resource->getName(),
            'email' => $this->resource->getEmail(),
            'role' => $this->resource->getRole(),
            'data' => [
                'displayName' => $this->resource->getName(),
                'photoURL' => 'assets/images/avatars/brian-hughes.jpg',
                'email' => $this->resource->getEmail(),
            ]
        ];
    }
}
