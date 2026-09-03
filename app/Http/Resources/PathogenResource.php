<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Core\Entities\PathogenEntity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PathogenEntity
 */
class PathogenResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PathogenEntity $this */
        return [
            'id' => $this->getId(),
            'code' => $this->getCode(),
            'name' => $this->getName(),
            'category' => $this->getCategory()->value,
            'is_disqualifying' => $this->isDisqualifying(),
            'description' => $this->getDescription(),
        ];
    }
}
