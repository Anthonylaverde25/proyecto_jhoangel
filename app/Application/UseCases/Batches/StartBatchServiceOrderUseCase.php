<?php

declare(strict_types=1);

namespace App\Application\UseCases\Batches;

use App\Application\DTOs\ServiceOrders\StartBatchServiceOrderDTO;
use App\Core\Entities\ServiceOrderEntity;
use App\Core\Enums\AnimalSex;
use App\Core\Enums\BatchWeightCause;
use App\Core\Enums\ServiceOrderStatus;
use App\Core\Exceptions\ServiceOrderDomainException;
use App\Core\Interfaces\IBatchRepository;
use App\Core\Interfaces\IBatchTypeRepository;
use App\Core\Interfaces\ICaravanRepository;
use App\Core\Interfaces\IServiceOrderRepository;
use App\Core\Services\BatchWeightService;
use App\Core\Services\BullServiceFitnessPolicy;
use App\Models\Batch;
use App\Models\Caravan;
use App\Models\CaravanMovement;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderFemale;
use App\Models\ServiceOrderHistory;
use App\Models\ServiceOrderMale;
use Illuminate\Support\Facades\DB;

final class StartBatchServiceOrderUseCase
{
    public function __construct(
        private readonly IBatchRepository $batchRepository,
        private readonly IBatchTypeRepository $batchTypeRepository,
        private readonly IServiceOrderRepository $serviceOrderRepository,
        private readonly ICaravanRepository $caravanRepository,
        private readonly BullServiceFitnessPolicy $fitnessPolicy,
        private readonly BatchWeightService $batchWeightService
    ) {
    }

    /**
     * @throws ServiceOrderDomainException
     */
    public function __invoke(StartBatchServiceOrderDTO $dto): ServiceOrderEntity
    {
        // 1. Validar lote base de origen
        $originBatch = Batch::withoutGlobalScopes()
            ->where('company_id', $dto->companyId)
            ->find($dto->originBatchId);

        if ($originBatch === null) {
            throw ServiceOrderDomainException::domainError("El lote de origen con ID {$dto->originBatchId} no existe.");
        }

        if ($originBatch->farm_id !== null && $originBatch->farm?->provider_id !== null) {
            throw ServiceOrderDomainException::domainError("El lote de origen debe ser un lote propio interno.");
        }

        $activityCode = strtoupper((string) ($originBatch->activity?->code ?? ''));
        $activityName = mb_strtolower((string) ($originBatch->activity?->name ?? ''));
        if ($activityCode !== 'CRIA' && !str_contains($activityName, 'cría') && !str_contains($activityName, 'cria')) {
            throw ServiceOrderDomainException::domainError("El lote de origen debe pertenecer a la actividad de 'Cría'.");
        }

        if ($originBatch->isInService()) {
            throw ServiceOrderDomainException::domainError(
                "El lote '{$originBatch->name}' ya cuenta con un servicio activo en curso."
            );
        }

        // 2. Resolver tipo de lote 'SERVICE'
        $serviceBatchType = $this->batchTypeRepository->findByCode('SERVICE');
        if ($serviceBatchType === null) {
            throw ServiceOrderDomainException::domainError("El tipo de lote 'SERVICE' no está configurado en el sistema.");
        }

        // 3. Cargar y validar hembras del lote de origen
        $femaleQuery = Caravan::withoutGlobalScopes()
            ->where('company_id', $dto->companyId)
            ->where('batch_id', $originBatch->id)
            ->where(function ($q) {
                $q->where('sex', AnimalSex::FEMALE->value)
                  ->orWhere('sex', 'H')
                  ->orWhere('sex', 'F');
            });

        if (!empty($dto->selectedFemaleCaravanIds)) {
            $femaleQuery->whereIn('id', $dto->selectedFemaleCaravanIds);
        }

        $femaleCaravans = $femaleQuery->with(['currentWeight', 'currentBodyCondition', 'femaleDetail'])->get();

        if ($femaleCaravans->isEmpty()) {
            throw ServiceOrderDomainException::domainError("El lote de origen no cuenta con hembras disponibles para entore.");
        }

        if (empty($dto->maleCaravanIds)) {
            throw ServiceOrderDomainException::domainError("Debe seleccionar al menos un reproductor macho para el servicio.");
        }

        // 4. Validar aptitud de toros (ADR-5 / BullServiceFitnessPolicy)
        $maleCaravans = [];
        foreach ($dto->maleCaravanIds as $maleId) {
            $bull = Caravan::withoutGlobalScopes()
                ->where('company_id', $dto->companyId)
                ->with(['currentWeight', 'bullHealthEvaluation'])
                ->find($maleId);

            if ($bull === null) {
                throw ServiceOrderDomainException::domainError("El reproductor macho con ID {$maleId} no fue encontrado.");
            }

            $sexVal = $bull->sex instanceof AnimalSex ? $bull->sex->value : (string)$bull->sex;
            if ($sexVal !== AnimalSex::MALE->value && $sexVal !== 'M') {
                throw ServiceOrderDomainException::invalidAnimalSex($maleId, 'male', $sexVal);
            }

            // Guarda Andrológica y Clínica Estricta
            $this->fitnessPolicy->assertFit($maleId, $dto->companyId, (string)$bull->identification);
            $maleCaravans[] = $bull;
        }

        // 5. Verificar que ningún animal esté activo en otra orden simultánea
        $allCaravanIds = array_merge(
            $femaleCaravans->pluck('id')->map(fn($id) => (int)$id)->toArray(),
            $dto->maleCaravanIds
        );
        $conflicts = $this->serviceOrderRepository->findActiveOrdersByCaravans($allCaravanIds, $dto->companyId);
        if (!empty($conflicts)) {
            throw ServiceOrderDomainException::activeOrderConflict('caravan', reset($conflicts));
        }

        // 6. Generar código canónico de la orden
        $year = date('Y', strtotime($dto->plannedStartDate));
        $count = ServiceOrder::withoutGlobalScopes()
            ->where('company_id', $dto->companyId)
            ->whereYear('planned_start_date', $year)
            ->count() + 1;
        $orderCode = sprintf('SO-%s-%03d', $year, $count);

        $createdOrderId = 0;
        $donorBullBatchIds = [];

        // 7. Transacción Atómica
        DB::transaction(function () use (
            $dto,
            $originBatch,
            $serviceBatchType,
            $orderCode,
            $femaleCaravans,
            $maleCaravans,
            &$createdOrderId,
            &$donorBullBatchIds
        ) {
            // A. Crear el Lote de Servicio en batches
            $serviceBatchName = $dto->serviceBatchName ?? "{$originBatch->name} - Servicio {$orderCode}";
            $serviceBatch = Batch::create([
                'company_id'    => $dto->companyId,
                'farm_id'       => $originBatch->farm_id,
                'activity_id'   => $originBatch->activity_id,
                'batch_type_id' => $serviceBatchType->getId(),
                'name'          => $serviceBatchName,
                'is_active'     => true,
                'is_system'     => false,
                'observaciones' => "Lote de servicio generado a partir de {$originBatch->name} para orden {$orderCode}",
            ]);

            // B. Crear la orden de servicio enlazando ambos lotes
            $serviceOrder = ServiceOrder::create([
                'company_id'            => $dto->companyId,
                'origin_batch_id'       => $originBatch->id,
                'service_batch_id'      => $serviceBatch->id,
                'batch_id'              => $serviceBatch->id,
                'code'                  => $orderCode,
                'status'                => ServiceOrderStatus::APPROVED->value,
                'service_type'          => $dto->serviceType,
                'is_controlled_service' => $dto->isControlledService,
                'target_bull_ratio'     => $dto->targetBullRatio,
                'planned_start_date'    => $dto->plannedStartDate,
                'actual_start_date'     => $dto->plannedStartDate,
                'planned_end_date'      => $dto->plannedEndDate,
                'requested_by_user_id'  => $dto->userId,
                'approved_by_user_id'   => $dto->userId,
                'approved_at'           => now(),
                'executed_at'           => now(),
                'observations'          => $dto->observations ?? "Servicio iniciado automáticamente desde lote {$originBatch->name}",
            ]);

            $createdOrderId = (int) $serviceOrder->id;

            // C. Snapshots de Hembras y Cálculo de Masa
            $femaleTotalWeight = 0.0;
            $femaleWeighedCount = 0;
            foreach ($femaleCaravans as $female) {
                $assignedSireId = null;
                if ($dto->isControlledService && !empty($dto->femaleSireAssignments)) {
                    foreach ($dto->femaleSireAssignments as $assignment) {
                        if ((int)($assignment['female_caravan_id'] ?? 0) === (int)$female->id) {
                            $assignedSireId = (int)$assignment['assigned_male_caravan_id'];
                            break;
                        }
                    }
                }

                $entryWeight = $female->currentWeight?->weight ?? $female->entry_weight;
                $bodyCondition = $female->currentBodyCondition?->score ?? $female->femaleDetail?->body_condition;

                if ($entryWeight !== null && (float)$entryWeight > 0) {
                    $femaleTotalWeight += (float)$entryWeight;
                    $femaleWeighedCount++;
                }

                ServiceOrderFemale::create([
                    'company_id'               => $dto->companyId,
                    'service_order_id'         => $serviceOrder->id,
                    'female_caravan_id'        => $female->id,
                    'assigned_male_caravan_id' => $assignedSireId,
                    'snapshot_entry_weight'    => $entryWeight,
                    'snapshot_body_condition'  => $bodyCondition,
                    'reproductive_status'      => 'UNCHECKED',
                ]);
            }

            // D. Snapshots de Machos, Cálculo de Masa y Transferencia Física al Lote de Origen
            $maleTotalWeight = 0.0;
            $maleWeighedCount = 0;
            foreach ($maleCaravans as $bull) {
                $originBullBatchId = $bull->batch_id;
                if ($originBullBatchId !== null && $originBullBatchId !== $originBatch->id) {
                    $donorBullBatchIds[$originBullBatchId] = true;
                }

                $bullWeight = $bull->currentWeight?->weight ?? $bull->entry_weight;
                if ($bullWeight !== null && (float)$bullWeight > 0) {
                    $maleTotalWeight += (float)$bullWeight;
                    $maleWeighedCount++;
                }

                $scrotalCirc = $bull->bullHealthEvaluation?->scrotal_circumference_cm;
                $serviceCapacity = 'HIGH';

                ServiceOrderMale::create([
                    'company_id'                     => $dto->companyId,
                    'service_order_id'               => $serviceOrder->id,
                    'male_caravan_id'                => $bull->id,
                    'snapshot_scrotal_circumference' => $scrotalCirc,
                    'service_capacity'               => (string) $serviceCapacity,
                    'status'                         => 'ACTIVE',
                ]);

                // Físicamente los toros ingresan al rodeo y potrero del Lote de Origen
                $bull->batch_id = $originBatch->id;
                $bull->save();

                CaravanMovement::create([
                    'company_id'    => $dto->companyId,
                    'caravan_id'    => $bull->id,
                    'renspa'        => $bull->renspa !== null && $bull->renspa !== '' && $bull->renspa !== 'NO_DEFINIDO' ? $bull->renspa : 'NO_DEFINIDO',
                    'type'          => 'TRANSFER',
                    'movement_date' => $dto->plannedStartDate,
                    'from_batch_id' => $originBullBatchId,
                    'to_batch_id'   => $originBatch->id,
                    'observations'  => "Ingreso de reproductor a servicio en '{$originBatch->name}' (Orden {$orderCode})"
                ]);
            }

            // E. Recalcular masas:
            // 1) Lotes donantes de toros (salida física)
            foreach (array_keys($donorBullBatchIds) as $donorId) {
                $this->batchWeightService->recalculateBatchWeight((int) $donorId, BatchWeightCause::MOVEMENT_OUT);
            }

            // 2) Lote de Origen (ingreso físico de los toros: vacas + toros)
            $this->batchWeightService->recalculateBatchWeight((int) $originBatch->id, BatchWeightCause::MOVEMENT_IN);

            // 3) Lote de Servicio (contenedor organizacional y zootécnico del entore)
            $totalServiceHeads = count($femaleCaravans) + count($maleCaravans);
            $totalServiceWeight = $femaleTotalWeight + $maleTotalWeight;
            $totalWeighedCount = $femaleWeighedCount + $maleWeighedCount;
            $avgServiceWeight = $totalWeighedCount > 0 ? round($totalServiceWeight / $totalWeighedCount, 1) : null;

            $serviceBatch->update([
                'caravans_count' => $totalServiceHeads,
                'current_weight' => $avgServiceWeight,
                'total_weight'   => $totalServiceWeight > 0 ? $totalServiceWeight : null,
                'weighed_count'  => $totalWeighedCount,
            ]);

            \App\Models\BatchWeight::create([
                'batch_id'       => $serviceBatch->id,
                'activity_id'    => $originBatch->activity_id,
                'weight'         => $avgServiceWeight,
                'total_weight'   => $totalServiceWeight > 0 ? $totalServiceWeight : null,
                'caravans_count' => $totalServiceHeads,
                'weighed_count'  => $totalWeighedCount,
                'type'           => BatchWeightCause::MOVEMENT_IN->value,
                'weighing_date'  => $dto->plannedStartDate,
            ]);

            // F. Auditoría
            ServiceOrderHistory::create([
                'company_id'       => $dto->companyId,
                'service_order_id' => $serviceOrder->id,
                'action_user_id'   => $dto->userId,
                'from_status'      => ServiceOrderStatus::DRAFT->value,
                'to_status'        => ServiceOrderStatus::APPROVED->value,
                'action_reason'    => "Servicio de entore iniciado desde lote base '{$originBatch->name}' generando lote '{$serviceBatch->name}'"
            ]);
        });

        $persistedEntity = $this->serviceOrderRepository->findById($createdOrderId, $dto->companyId);
        if ($persistedEntity === null) {
            throw ServiceOrderDomainException::domainError("Error al recuperar la orden de servicio creada.");
        }

        return $persistedEntity;
    }
}
