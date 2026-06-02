<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Core\Entities\BirthHistoryEntity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read BirthHistoryEntity $resource
 */
class BirthHistoryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'gestation_id'          => $this->resource->getGestationId(),
            'mother_id'             => $this->resource->getMotherId(),
            'mother_identification' => $this->resource->getMotherIdentification(),
            'birth_date'            => $this->resource->getBirthDate(),
            'notes'                 => $this->resource->getNotes(),
            'calf_id'               => $this->resource->getCalfId(),
            'calf_identification'   => $this->resource->getCalfIdentification(),
            'is_nursing'            => $this->resource->isNursing(),
            'calf_sex'              => $this->resource->getCalfSex(),
            'calf_batch_name'       => $this->resource->getCalfBatchName(),
        ];
    }
}
