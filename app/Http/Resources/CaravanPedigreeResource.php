<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CaravanPedigreeResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'caravan' => $this->resource['caravan'],
            'inbreeding' => $this->resource['inbreeding'],
            'tree' => $this->resource['tree'],
            'offspring' => $this->resource['offspring'],
        ];
    }
}
