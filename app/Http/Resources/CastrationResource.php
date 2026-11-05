<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Core\Enums\CastrationMethod;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CastrationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $method = $this['method'] ?? null;
        $methodLabel = null;
        if ($method !== null) {
            $methodEnum = CastrationMethod::tryFrom($method);
            $methodLabel = $methodEnum?->label();
        }

        return [
            'id'               => $this['id'],
            'caravan_id'       => $this['caravan_id'],
            'castrated_on'     => $this['castrated_on'],
            'method'           => $method,
            'method_label'     => $methodLabel,
            'notes'            => $this['notes'] ?? null,
            'from_category_id' => $this['from_category_id'] ?? null,
            'voided_at'        => $this['voided_at'] ?? null,
            'void_reason'      => $this['void_reason'] ?? null,
        ];
    }
}

