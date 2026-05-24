<?php

declare(strict_types=1);

namespace App\Application\Mappers;

use App\Core\Entities\ServiceOrderEntity;
use App\Core\Entities\ServiceOrderHistoryEntity;
use App\Core\Enums\ServiceOrderStatus;
use App\Models\ServiceOrder;

class ServiceOrderMapper
{
    public static function toEntity(ServiceOrder $model): ServiceOrderEntity
    {
        $maleIds = [];
        if ($model->relationLoaded('males') && $model->males !== null) {
            $maleIds = $model->males->pluck('id')->map(fn($id) => (int)$id)->toArray();
        }

        $femaleIds = [];
        if ($model->relationLoaded('females') && $model->females !== null) {
            $femaleIds = $model->females->pluck('id')->map(fn($id) => (int)$id)->toArray();
        }

        $historyEntities = [];
        if ($model->relationLoaded('history') && $model->history !== null) {
            foreach ($model->history as $h) {
                $historyEntities[] = new ServiceOrderHistoryEntity(
                    $h->id,
                    (int) $h->company_id,
                    (int) $h->service_order_id,
                    $h->from_status,
                    $h->to_status,
                    (int) $h->action_user_id,
                    $h->action_reason,
                    $h->action_metadata,
                    $h->created_at
                );
            }
        }

        return new ServiceOrderEntity(
            id: $model->id,
            companyId: (int) $model->company_id,
            batchId: (int) $model->batch_id,
            code: $model->code,
            status: ServiceOrderStatus::from($model->status),
            plannedStartDate: $model->planned_start_date instanceof \DateTimeInterface 
                ? $model->planned_start_date->format('Y-m-d') 
                : (string)$model->planned_start_date,
            requestedByUserId: $model->requested_by_user_id !== null ? (int) $model->requested_by_user_id : null,
            reviewedByUserId: $model->reviewed_by_user_id !== null ? (int) $model->reviewed_by_user_id : null,
            approvedByUserId: $model->approved_by_user_id !== null ? (int) $model->approved_by_user_id : null,
            reviewedAt: $model->reviewed_at,
            approvedAt: $model->approved_at,
            executedAt: $model->executed_at,
            actualStartDate: $model->actual_start_date instanceof \DateTimeInterface 
                ? $model->actual_start_date->format('Y-m-d') 
                : ($model->actual_start_date ? (string)$model->actual_start_date : null),
            actualEndDate: $model->actual_end_date instanceof \DateTimeInterface 
                ? $model->actual_end_date->format('Y-m-d') 
                : ($model->actual_end_date ? (string)$model->actual_end_date : null),
            observations: $model->observations,
            rejectionReason: $model->rejection_reason,
            maleCaravanIds: $maleIds,
            femaleCaravanIds: $femaleIds,
            history: $historyEntities,
            createdAt: $model->created_at,
            updatedAt: $model->updated_at
        );
    }

    public static function toModel(ServiceOrderEntity $entity, ?ServiceOrder $model = null): ServiceOrder
    {
        if ($model === null) {
            $model = new ServiceOrder();
        }

        $model->company_id = $entity->getCompanyId();
        $model->batch_id = $entity->getBatchId();
        $model->code = $entity->getCode();
        $model->status = $entity->getStatus()->value;
        $model->requested_by_user_id = $entity->getRequestedByUserId();
        $model->reviewed_by_user_id = $entity->getReviewedByUserId();
        $model->approved_by_user_id = $entity->getApprovedByUserId();
        $model->reviewed_at = $entity->getReviewedAt();
        $model->approved_at = $entity->getApprovedAt();
        $model->executed_at = $entity->getExecutedAt();
        $model->planned_start_date = $entity->getPlannedStartDate();
        $model->actual_start_date = $entity->getActualStartDate();
        $model->actual_end_date = $entity->getActualEndDate();
        $model->observations = $entity->getObservations();
        $model->rejection_reason = $entity->getRejectionReason();

        return $model;
    }
}
