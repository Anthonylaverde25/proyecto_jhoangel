<?php

declare(strict_types=1);

namespace App\Application\UseCases\ServiceOrders;

use App\Application\DTOs\ServiceOrders\CloseServiceOrderDTO;
use App\Application\Mappers\ServiceOrderMapper;
use App\Core\Entities\ServiceOrderEntity;
use App\Core\Enums\BatchWeightCause;
use App\Core\Enums\ServiceOrderMaleStatus;
use App\Core\Enums\ServiceOrderStatus;
use App\Core\Exceptions\ServiceOrderDomainException;
use App\Core\Interfaces\IServiceOrderRepository;
use App\Core\Services\BatchWeightService;
use App\Models\Batch;
use App\Models\Caravan;
use App\Models\CaravanMovement;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderHistory;
use App\Models\ServiceOrderMale;
use Illuminate\Support\Facades\DB;

final class CloseServiceOrderWithBullWithdrawalUseCase
{
    public function __construct(
        private readonly IServiceOrderRepository $serviceOrderRepository,
        private readonly BatchWeightService $batchWeightService
    ) {
    }

    /**
     * @throws ServiceOrderDomainException
     */
    public function __invoke(CloseServiceOrderDTO $dto): ServiceOrderEntity
    {
        // 1. Validar existencia y vigencia de la orden de servicio
        $serviceOrder = ServiceOrder::withoutGlobalScopes()
            ->where('id', $dto->serviceOrderId)
            ->where('company_id', $dto->companyId)
            ->first();

        if ($serviceOrder === null) {
            throw ServiceOrderDomainException::domainError("La orden de servicio con ID {$dto->serviceOrderId} no existe.");
        }

        if ($serviceOrder->status !== ServiceOrderStatus::APPROVED->value) {
            throw ServiceOrderDomainException::domainError(
                "Solo se pueden finalizar órdenes de servicio aprobadas o en curso (Estado actual: {$serviceOrder->status})."
            );
        }

        // 2. Obtener todos los machos activos de la orden
        $activeServiceMales = ServiceOrderMale::where('service_order_id', $serviceOrder->id)
            ->where('status', ServiceOrderMaleStatus::ACTIVE->value)
            ->get();

        $activeBullIds = $activeServiceMales->pluck('male_caravan_id')->toArray();
        $activeBulls = Caravan::withoutGlobalScopes()
            ->whereIn('id', $activeBullIds)
            ->where('company_id', $dto->companyId)
            ->get()
            ->keyBy('id');

        $targetServiceBatchId = (int) ($serviceOrder->service_batch_id ?? $serviceOrder->batch_id);

        // 3. Pre-validar resolución de lotes destino para todos los toros activos
        $resolvedDestinations = [];
        $uniqueDestinationBatchIds = [];

        foreach ($activeServiceMales as $asm) {
            $bullId = (int) $asm->male_caravan_id;
            $bull = $activeBulls->get($bullId);

            if ($bull === null) {
                throw ServiceOrderDomainException::domainError("El reproductor con ID {$bullId} no fue encontrado.");
            }

            // Resolver lote de destino
            $destId = $dto->bullDestinations[$bullId] ?? $dto->defaultDestinationBatchId;

            // Fallback: verificar de qué lote provenía el toro antes de ingresar al servicio
            if ($destId === null) {
                $lastEntryMovement = CaravanMovement::where('caravan_id', $bullId)
                    ->where('company_id', $dto->companyId)
                    ->where('to_batch_id', $targetServiceBatchId)
                    ->latest('movement_date')
                    ->first();

                $destId = $lastEntryMovement?->from_batch_id;
            }

            if ($destId === null) {
                throw ServiceOrderDomainException::domainError(
                    "Debe especificar un lote de destino para el toro #{$bull->identification}."
                );
            }

            $destBatch = Batch::withoutGlobalScopes()
                ->where('id', $destId)
                ->where('company_id', $dto->companyId)
                ->first();

            if ($destBatch === null) {
                throw ServiceOrderDomainException::domainError(
                    "El lote de destino con ID {$destId} para el toro #{$bull->identification} no existe en esta empresa."
                );
            }

            $resolvedDestinations[$bullId] = $destId;
            $uniqueDestinationBatchIds[$destId] = true;
        }

        // 4. Transacción Atómica
        return DB::transaction(function () use (
            $dto,
            $serviceOrder,
            $activeServiceMales,
            $activeBulls,
            $targetServiceBatchId,
            $resolvedDestinations,
            $uniqueDestinationBatchIds
        ) {
            $withdrawalTimestamp = $dto->withdrawalDate;

            // A. Procesar retiro y traslado de cada toro activo
            foreach ($activeServiceMales as $asm) {
                $bullId = (int) $asm->male_caravan_id;
                $bull = $activeBulls->get($bullId);
                $destBatchId = $resolvedDestinations[$bullId];

                // 1. Marcar como completado en service_order_males
                $asm->update([
                    'status'     => ServiceOrderMaleStatus::COMPLETED->value,
                    'retired_at' => $withdrawalTimestamp,
                ]);

                // 2. Mover físicamente al toro si cambia de lote
                $previousBatchId = $bull->batch_id;
                if ($previousBatchId !== $destBatchId) {
                    $bull->batch_id = $destBatchId;
                    $bull->save();

                    CaravanMovement::create([
                        'company_id'    => $dto->companyId,
                        'caravan_id'    => $bull->id,
                        'renspa'        => $bull->renspa ?: 'NO_DEFINIDO',
                        'type'          => 'TRANSFER',
                        'movement_date' => $withdrawalTimestamp,
                        'from_batch_id' => $previousBatchId,
                        'to_batch_id'   => $destBatchId,
                        'observations'  => "Retiro total de torada por finalización de orden {$serviceOrder->code}" .
                            ($dto->observations ? ": {$dto->observations}" : ''),
                    ]);
                }
            }

            // B. Recalcular masas en BatchWeightService
            $this->batchWeightService->recalculateBatchWeight($targetServiceBatchId, BatchWeightCause::MOVEMENT_OUT);

            foreach (array_keys($uniqueDestinationBatchIds) as $destBatchId) {
                if ($destBatchId !== $targetServiceBatchId) {
                    $this->batchWeightService->recalculateBatchWeight($destBatchId, BatchWeightCause::MOVEMENT_IN);
                }
            }

            // C. Actualizar estado y fechas de la orden de servicio
            $updatedObservations = $serviceOrder->observations;
            if ($dto->observations !== null && trim($dto->observations) !== '') {
                $updatedObservations = $updatedObservations
                    ? "{$updatedObservations} | Cierre: {$dto->observations}"
                    : $dto->observations;
            }

            $serviceOrder->update([
                'status'          => ServiceOrderStatus::SUCCESS->value,
                'actual_end_date' => $withdrawalTimestamp,
                'observations'    => $updatedObservations,
            ]);

            // D. Registrar auditoría en service_order_histories
            $bullsCount = count($activeServiceMales);
            ServiceOrderHistory::create([
                'company_id'       => $dto->companyId,
                'service_order_id' => $serviceOrder->id,
                'action_user_id'   => $dto->userId,
                'from_status'      => ServiceOrderStatus::APPROVED->value,
                'to_status'        => ServiceOrderStatus::SUCCESS->value,
                'action_reason'    => "Finalización de servicio reproductivo y retiro total de torada ({$bullsCount} reproductores trasladados a descanso).",
            ]);

            // E. Retornar entidad actualizada
            $freshOrder = ServiceOrder::with([
                'males',
                'females',
                'history',
                'serviceOrderMales',
                'bullReplacements.retiredMaleCaravan',
                'bullReplacements.replacementMaleCaravan',
                'bullReplacements.destinationBatch',
                'bullReplacements.user',
            ])->find($serviceOrder->id);

            return ServiceOrderMapper::toEntity($freshOrder);
        });
    }
}
