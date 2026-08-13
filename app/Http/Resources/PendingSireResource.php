<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Core\Entities\LineageEntity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read LineageEntity $resource
 *
 * Note: This resource receives a LineageEntity, but the controller passes additional
 * context (raw CaravanLineage model) via the `additional()` method to populate
 * calf identification, batch name, and candidate sires.
 */
class PendingSireResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var \App\Models\CaravanLineage $model */
        $model = $this->resource;

        $birthDate = $model->birth_date instanceof \DateTimeInterface
            ? $model->birth_date->format('Y-m-d')
            : (string) $model->birth_date;

        $daysWithoutSire = (int) now()->diffInDays(\Carbon\Carbon::parse($birthDate));

        // Candidate sires come from the associated gestation's sires list
        $candidateSires = [];
        if ($model->gestation !== null) {
            $gestationSires = $model->gestation->sires ?? [];
            foreach ($gestationSires as $sire) {
                $candidateSires[] = [
                    'id'             => $sire['sire_id'] ?? null,
                    'identification' => $sire['sire_identification'] ?? null,
                    'is_confirmed'   => $sire['is_confirmed'] ?? false,
                ];
            }
        }

        return [
            'calf_id'              => $model->caravan_id,
            'calf_identification'  => $model->caravan?->identification ?? '—',
            'calf_sex'             => $model->caravan?->sex ?? null,
            'birth_date'           => $birthDate,
            'days_without_sire'    => $daysWithoutSire,
            'mother_id'            => $model->mother_id,
            'mother_identification'=> $model->mother?->identification ?? '—',
            'gestation_id'         => $model->gestation_id,
            'batch_name'           => $model->caravan?->batch?->name ?? null,
            'batch_id'             => $model->caravan?->batch_id ?? null,
            'candidate_sires'      => $candidateSires,
        ];
    }
}
