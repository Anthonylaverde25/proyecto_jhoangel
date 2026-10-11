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
     * @var array<string, mixed>|null
     */
    private ?array $entryOrder = null;

    /**
     * @var list<array<string, mixed>>
     */
    private array $incomingEntryOrders = [];

    /**
     * The entry order the batch was born from, when it is an external batch of a purchase.
     *
     * @param array<string, mixed>|null $summary
     */
    public function withEntryOrder(?array $summary): self
    {
        $this->entryOrder = $summary;

        return $this;
    }

    /**
     * The open entry orders that bring animals to this own batch.
     *
     * @param list<array<string, mixed>> $summaries
     */
    public function withIncomingEntryOrders(array $summaries): self
    {
        $this->incomingEntryOrders = $summaries;

        return $this;
    }

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
            'total_weight'  => $this->resource->getTotalWeight(),
            'caravans_count'=> $this->resource->getCaravansCount(),
            // Received by count, their caravans still to write: in the batch, not yet caravans.
            'pending_identification_count' => $this->resource->getPendingIdentificationCount() ?? 0,
            // What the stock views count: caravans plus head pending identification.
            'head_count'    => $this->resource->getHeadCount(),
            'weighed_count' => $this->resource->getWeighedCount(),
            'is_active'     => $this->resource->isActive(),
            'is_system'    => $this->resource->isSystem(),
            'min_weight'     => $this->resource->getMinWeight(),
            'max_weight'     => $this->resource->getMaxWeight(),
            'knows_to_eat'   => $this->resource->knowsToEat(),
            'is_confined'    => $this->resource->isConfined(),
            'age_in_months'  => $this->resource->getAgeInMonths(),
            'batch_type_id'   => $this->resource->getBatchTypeId(),
            'batch_type_name' => $this->resource->getBatchTypeName(),
            'batch_type_code' => $this->resource->getBatchTypeCode(),
            'is_service_batch'=> $this->resource->isServiceBatch(),
            'is_weaning_batch'=> $this->resource->isWeaningBatch(),
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
            'is_in_service'   => $this->resource->getId() ? \App\Models\ServiceOrder::where('origin_batch_id', $this->resource->getId())->where('status', 'APPROVED')->exists() : false,
            'active_service_order' => $this->resource->getId() ? \App\Models\ServiceOrder::where('origin_batch_id', $this->resource->getId())->where('status', 'APPROVED')->select('id', 'code', 'status', 'planned_start_date', 'service_batch_id')->first() : null,
            'service_order_origin_batch_id' => $this->resource->getId() ? \App\Models\ServiceOrder::where('service_batch_id', $this->resource->getId())->value('origin_batch_id') : null,
            'entry_order'   => $this->entryOrder,
            'incoming_entry_orders' => $this->incomingEntryOrders,
            'created_at'    => $this->resource->getCreatedAt()?->format('Y-m-d H:i:s'),
        ];

    }
}
