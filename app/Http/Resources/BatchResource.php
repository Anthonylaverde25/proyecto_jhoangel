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
        $activeService = $this->activeService();

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
            'is_weaning_batch'=> $this->resource->isWeaningBatch(),
            'is_in_service'   => $activeService !== null && $activeService['status'] === 'APPROVED',
            'active_service_order' => $activeService,
            'entry_order'   => $this->entryOrder,
            'incoming_entry_orders' => $this->incomingEntryOrders,
            'created_at'    => $this->resource->getCreatedAt()?->format('Y-m-d H:i:s'),
        ];

    }

    /**
     * The service running on the batch (DRAFT or APPROVED). Read from the entity when the
     * repository eager loaded it, so a batch list does not query once per row.
     *
     * @return array{id: int, code: string, status: string, planned_start_date: ?string, is_controlled_service: bool}|null
     */
    private function activeService(): ?array
    {
        if ($this->resource->isActiveServiceOrderLoaded()) {
            return $this->resource->getActiveServiceOrder();
        }

        $batchId = $this->resource->getId();
        if ($batchId === null) {
            return null;
        }

        $order = \App\Models\ServiceOrder::where('batch_id', $batchId)
            ->whereIn('status', ['DRAFT', 'APPROVED'])
            ->first(['id', 'code', 'status', 'planned_start_date', 'is_controlled_service']);

        return $order === null ? null : [
            'id' => (int) $order->id,
            'code' => (string) $order->code,
            'status' => (string) $order->status,
            'planned_start_date' => $order->planned_start_date?->format('Y-m-d'),
            'is_controlled_service' => (bool) $order->is_controlled_service,
        ];
    }
}
