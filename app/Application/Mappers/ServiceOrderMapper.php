<?php

declare(strict_types=1);

namespace App\Application\Mappers;

use App\Core\Entities\ServiceOrderEntity;
use App\Core\Entities\ServiceOrderHistoryEntity;
use App\Core\Enums\ServiceMethod;
use App\Core\Enums\ServiceOrderStatus;
use App\Models\ServiceOrder;

class ServiceOrderMapper
{
    public static function toEntity(ServiceOrder $model): ServiceOrderEntity
    {
        $allMaleIds = [];
        $activeMaleIds = [];
        if ($model->relationLoaded('males') && $model->males !== null) {
            foreach ($model->males as $male) {
                $mId = (int) $male->id;
                $allMaleIds[] = $mId;
                $status = $male->pivot->status ?? 'ACTIVE';
                if ($status === 'ACTIVE') {
                    $activeMaleIds[] = $mId;
                }
            }
        }
        $maleIds = !empty($activeMaleIds) ? $activeMaleIds : $allMaleIds;

        $femaleIds = [];
        $femaleSireAssignments = [];
        $femaleExposures = [];
        $lastInsemination = [];
        if ($model->relationLoaded('lastInseminations')) {
            foreach ($model->lastInseminations as $row) {
                $lastInsemination[(int) $row->female_caravan_id] = self::dateOrNull($row->last_inseminated_on);
            }
        }
        if ($model->relationLoaded('females') && $model->females !== null) {
            $femaleIds = $model->females->pluck('id')->map(fn($id) => (int)$id)->toArray();
            foreach ($model->females as $female) {
                $femaleExposures[] = [
                    'female_caravan_id' => (int) $female->id,
                    'exposed_from' => self::dateOrNull($female->pivot->exposed_from ?? null),
                    'exposed_until' => self::dateOrNull($female->pivot->exposed_until ?? null),
                    'exit_reason' => $female->pivot->exit_reason ?? null,
                    'joined_after_start' => (bool) ($female->pivot->joined_after_start ?? false),
                    'reproductive_status' => $female->pivot->reproductive_status ?? 'UNCHECKED',
                    'diagnosed_at' => self::dateOrNull($female->pivot->diagnosed_at ?? null),
                    'last_insemination_on' => $lastInsemination[(int) $female->id] ?? null,
                    'planned_semen_item_id' => isset($female->pivot->planned_semen_item_id) ? (int) $female->pivot->planned_semen_item_id : null,
                    'not_inseminated_reason' => $female->pivot->not_inseminated_reason ?? null,
                ];

                if (isset($female->pivot) && $female->pivot->assigned_male_caravan_id !== null) {
                    $femaleSireAssignments[] = [
                        'female_caravan_id' => (int) $female->id,
                        'assigned_male_caravan_id' => (int) $female->pivot->assigned_male_caravan_id,
                    ];
                }
            }
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

        $maleDetails = [];
        $maleIdentifications = [];
        if ($model->relationLoaded('males') && $model->males !== null) {
            foreach ($model->males as $male) {
                $maleIdentifications[(int) $male->id] = (string) $male->identification;
            }
        }
        if ($model->relationLoaded('serviceOrderMales') && $model->serviceOrderMales !== null) {
            $activeMaleIds = [];
            $allMaleIds = [];
            foreach ($model->serviceOrderMales as $som) {
                $mId = (int) $som->male_caravan_id;
                $status = (string) ($som->status ?? 'ACTIVE');
                $allMaleIds[] = $mId;
                if ($status === 'ACTIVE') {
                    $activeMaleIds[] = $mId;
                }
                $maleDetails[] = [
                    'male_caravan_id' => $mId,
                    'identification' => $maleIdentifications[$mId] ?? null,
                    'status' => $status,
                    'joined_at' => self::dateOrNull($som->joined_at),
                    'retired_at' => $som->retired_at instanceof \DateTimeInterface ? $som->retired_at->format('Y-m-d H:i:s') : ($som->retired_at ? (string)$som->retired_at : null),
                    'scrotal_circumference' => $som->snapshot_scrotal_circumference !== null ? (float)$som->snapshot_scrotal_circumference : null,
                    'service_capacity' => $som->service_capacity,
                ];
            }
            $maleIds = !empty($activeMaleIds) ? $activeMaleIds : $allMaleIds;
        } elseif ($model->relationLoaded('males') && $model->males !== null) {
            foreach ($model->males as $male) {
                $maleDetails[] = [
                    'male_caravan_id' => (int) $male->id,
                    'identification' => (string) $male->identification,
                    'status' => $male->pivot->status ?? 'ACTIVE',
                    'joined_at' => self::dateOrNull($male->pivot->joined_at ?? null),
                    'retired_at' => isset($male->pivot->retired_at) && $male->pivot->retired_at instanceof \DateTimeInterface
                        ? $male->pivot->retired_at->format('Y-m-d H:i:s')
                        : (isset($male->pivot->retired_at) ? (string)$male->pivot->retired_at : null),
                    'scrotal_circumference' => isset($male->pivot->snapshot_scrotal_circumference) ? (float)$male->pivot->snapshot_scrotal_circumference : null,
                    'service_capacity' => $male->pivot->service_capacity ?? null,
                ];
            }
        }

        $bullReplacements = [];
        if ($model->relationLoaded('bullReplacements') && $model->bullReplacements !== null) {
            $bullReplacements = \App\Http\Resources\ServiceOrderBullReplacementResource::collection($model->bullReplacements)->resolve();
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
            updatedAt: $model->updated_at,
            serviceType: $model->service_type ?? 'single',
            isControlledService: (bool) ($model->is_controlled_service ?? false),
            femaleSireAssignments: $femaleSireAssignments,
            targetBullRatio: $model->target_bull_ratio !== null ? (float) $model->target_bull_ratio : 3.0,
            plannedEndDate: $model->planned_end_date instanceof \DateTimeInterface
                ? $model->planned_end_date->format('Y-m-d')
                : ($model->planned_end_date ? (string)$model->planned_end_date : null),
            finalPregnancyRate: $model->final_pregnancy_rate !== null ? (float) $model->final_pregnancy_rate : null,
            maleDetails: $maleDetails,
            bullReplacements: $bullReplacements,
            allMaleCaravanIds: $allMaleIds,
            femaleExposures: $femaleExposures,
            batchName: $model->relationLoaded('batch') ? $model->batch?->name : null,
            diagnosisCompletedAt: self::dateOrNull($model->diagnosis_completed_at),
            parentServiceOrderId: $model->parent_service_order_id !== null ? (int) $model->parent_service_order_id : null,
            serviceMethod: ServiceMethod::tryFrom((string) ($model->service_method ?? '')) ?? ServiceMethod::NATURAL
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
        $model->planned_end_date = $entity->getPlannedEndDate();
        $model->actual_start_date = $entity->getActualStartDate();
        $model->actual_end_date = $entity->getActualEndDate();
        $model->diagnosis_completed_at = $entity->getDiagnosisCompletedAt();
        $model->parent_service_order_id = $entity->getParentServiceOrderId();
        $model->target_bull_ratio = $entity->getTargetBullRatio();
        $model->final_pregnancy_rate = $entity->getFinalPregnancyRate();
        $model->observations = $entity->getObservations();
        $model->rejection_reason = $entity->getRejectionReason();
        $model->service_type = $entity->getServiceType();
        $model->is_controlled_service = $entity->isControlledService();
        $model->service_method = $entity->getServiceMethod()->value;

        return $model;
    }

    private static function dateOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : substr((string) $value, 0, 10);
    }
}
