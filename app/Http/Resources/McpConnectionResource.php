<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Core\Entities\McpConnectionEntity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin McpConnectionEntity
 */
class McpConnectionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var McpConnectionEntity $this */
        return [
            'id' => $this->getId(),
            'name' => $this->getName(),
            'source' => $this->getSource(),
            'created_at' => $this->getCreatedAt()?->format(DATE_ATOM),
            'last_used_at' => $this->getLastUsedAt()?->format(DATE_ATOM),
            'expires_at' => $this->getExpiresAt()->format(DATE_ATOM),
            'revoked_at' => $this->getRevokedAt()?->format(DATE_ATOM),
        ];
    }
}
