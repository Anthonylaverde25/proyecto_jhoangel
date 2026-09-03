<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Core\Entities\BatchEntity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read BatchEntity $resource
 */
class BatchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->resource->getId(),
            'name'          => $this->resource->getName(),
            'farm_id'       => $this->resource->getFarmId(),
            'farm_name'     => $this->resource->getFarmName(),
            'provider_id'   => $this->resource->getProviderId(),
            'provider_name' => $this->resource->getProviderName(),
            'renspa'        => $this->resource->getRenspa(),
            'observaciones' => $this->resource->getObservaciones(),
            'activity_id'   => $this->resource->getActivityId(),
            'activity_name' => $this->resource->getActivityName(),
            'activity_code' => $this->resource->getActivityCode(),
            'current_weight'=> $this->resource->getCurrentWeight(),
            'is_active'     => $this->resource->isActive(),
            'is_system'    => $this->resource->isSystem(),
            'min_weight'     => $this->resource->getMinWeight(),
            'max_weight'     => $this->resource->getMaxWeight(),
            'knows_to_eat'   => $this->resource->knowsToEat(),
            'age_in_months'  => $this->resource->getAgeInMonths(),
            'batch_type_id'   => $this->resource->getBatchTypeId(),
            'batch_type_name' => $this->resource->getBatchTypeName(),
            'batch_type_code' => $this->resource->getBatchTypeCode(),
            'is_service_batch'=> $this->resource->isServiceBatch(),
            'service_detail'  => $this->resource->getServiceDetail() ? [
                'id'                      => $this->resource->getServiceDetail()->getId(),
                'female_category_id'      => $this->resource->getServiceDetail()->getFemaleCategoryId(),
                'female_category_name'    => $this->resource->getServiceDetail()->getFemaleCategoryName(),
                'female_category_code'    => $this->resource->getServiceDetail()->getFemaleCategoryCode(),
                'female_subcategory_id'   => $this->resource->getServiceDetail()->getFemaleSubcategoryId(),
                'female_subcategory_name' => $this->resource->getServiceDetail()->getFemaleSubcategoryName(),
                'female_subcategory_code' => $this->resource->getServiceDetail()->getFemaleSubcategoryCode(),
                'male_category_id'        => $this->resource->getServiceDetail()->getMaleCategoryId(),
                'male_category_name'      => $this->resource->getServiceDetail()->getMaleCategoryName(),
                'male_category_code'      => $this->resource->getServiceDetail()->getMaleCategoryCode(),
                'target_bull_ratio'       => $this->resource->getServiceDetail()->getTargetBullRatio(),
                'planned_start_date'      => $this->resource->getServiceDetail()->getPlannedStartDate(),
                'planned_end_date'        => $this->resource->getServiceDetail()->getPlannedEndDate(),
                'notes'                   => $this->resource->getServiceDetail()->getNotes(),
            ] : null,
            'created_at'    => $this->resource->getCreatedAt()?->format('Y-m-d H:i:s'),
        ];

    }
}
