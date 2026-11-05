<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Core\Entities\CategoryChangeEntity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property CategoryChangeEntity $resource
 */
class CategoryChangeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $entity = $this->resource;

        return [
            'id'                      => $entity->getId(),
            'company_id'              => $entity->getCompanyId(),
            'caravan_id'              => $entity->getCaravanId(),
            'from_category_id'        => $entity->getFromCategoryId(),
            'from_category_code'      => $entity->getFromCategoryCode(),
            'from_category_name'      => $entity->getFromCategoryName(),
            'from_subcategory_id'     => $entity->getFromSubcategoryId(),
            'from_subcategory_name'   => $entity->getFromSubcategoryName(),
            'to_category_id'          => $entity->getToCategoryId(),
            'to_category_code'        => $entity->getToCategoryCode(),
            'to_category_name'        => $entity->getToCategoryName(),
            'to_subcategory_id'       => $entity->getToSubcategoryId(),
            'to_subcategory_name'     => $entity->getToSubcategoryName(),
            'cause'                   => $entity->getCause()->value,
            'cause_label'             => $entity->getCause()->label(),
            'occurred_on'             => $entity->getOccurredOn(),
            'source_type'             => $entity->getSourceType()?->value,
            'source_id'               => $entity->getSourceId(),
            'registered_by_user_id'   => $entity->getRegisteredByUserId(),
            'registered_by_user_name' => $entity->getRegisteredByUserName(),
            'created_at'              => $entity->getCreatedAt(),
        ];
    }
}

