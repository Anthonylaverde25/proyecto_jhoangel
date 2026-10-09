<?php

declare(strict_types=1);

namespace App\Application\UseCases\ServiceOrders;

use App\Application\DTOs\ServiceOrders\ReplaceServiceBullDTO;
use App\Core\Enums\AnimalSex;
use App\Core\Enums\BatchWeightCause;
use App\Core\Enums\BullReplacementReason;
use App\Core\Enums\ServiceOrderMaleStatus;
use App\Core\Enums\ServiceOrderStatus;
use App\Core\Exceptions\ServiceOrderDomainException;
use App\Core\Interfaces\IServiceOrderRepository;
use App\Core\Services\BatchWeightService;
use App\Core\Services\BullServiceFitnessPolicy;
use App\Models\Batch;
use App\Models\Caravan;
use App\Models\CaravanMovement;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderBullReplacement;
use App\Models\ServiceOrderHistory;
use App\Models\ServiceOrderMale;
use Illuminate\Support\Facades\DB;

final class ReplaceServiceBullUseCase
{
    public function __construct(
        private readonly IServiceOrderRepository $serviceOrderRepository,
        private readonly BullServiceFitnessPolicy $fitnessPolicy,
        private readonly BatchWeightService $batchWeightService
    ) {
    }

    /**
     * @throws ServiceOrderDomainException
     */
    public function __invoke(ReplaceServiceBullDTO $dto): ServiceOrderBullReplacement
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
                "Solo se pueden sustituir reproductores en órdenes de servicio aprobadas o en curso (Estado actual: {$serviceOrder->status})."
            );
        }

        // 2. Validar que el reproductor a retirar pertenezca a la orden y esté activo
        $retiredBull = Caravan::withoutGlobalScopes()
            ->where('id', $dto->retiredMaleCaravanId)
            ->where('company_id', $dto->companyId)
            ->first();

        if ($retiredBull === null) {
            throw ServiceOrderDomainException::domainError("El reproductor saliente con ID {$dto->retiredMaleCaravanId} no fue encontrado.");
        }

        $activeServiceMale = ServiceOrderMale::where('service_order_id', $serviceOrder->id)
            ->where('male_caravan_id', $dto->retiredMaleCaravanId)
            ->first();

        if ($activeServiceMale === null) {
            throw ServiceOrderDomainException::domainError(
                "El reproductor #{$retiredBull->identification} no está asignado a la orden de servicio {$serviceOrder->code}."
            );
        }

        if ($activeServiceMale->status !== ServiceOrderMaleStatus::ACTIVE->value) {
            throw ServiceOrderDomainException::domainError(
                "El reproductor #{$retiredBull->identification} ya no se encuentra activo en esta orden de servicio (Estado: {$activeServiceMale->status})."
            );
        }

        // 3. Validar reproductor suplente entrante
        $replacementBull = Caravan::withoutGlobalScopes()
            ->where('id', $dto->replacementMaleCaravanId)
            ->where('company_id', $dto->companyId)
            ->with(['currentWeight', 'bullHealthEvaluation'])
            ->first();

        if ($replacementBull === null) {
            throw ServiceOrderDomainException::domainError("El reproductor suplente con ID {$dto->replacementMaleCaravanId} no fue encontrado.");
        }

        $sexVal = $replacementBull->sex instanceof AnimalSex ? $replacementBull->sex->value : (string)$replacementBull->sex;
        if ($sexVal !== AnimalSex::MALE->value && $sexVal !== 'M') {
            throw ServiceOrderDomainException::invalidAnimalSex($replacementBull->id, 'male', $sexVal);
        }

        // Invariante de No-Rotación: Verificar que el toro suplente NO esté activo en otra orden simultánea
        $conflicts = $this->serviceOrderRepository->findActiveOrdersByCaravans(
            [$dto->replacementMaleCaravanId],
            $dto->companyId
        );
        if (!empty($conflicts)) {
            throw ServiceOrderDomainException::domainError(
                "El reproductor suplente #{$replacementBull->identification} ya se encuentra participando en otra orden de servicio activa (Invariante de No-Rotación)."
            );
        }

        // Guarda Andrológica y Sanitaria Estricta (ADR-5)
        $this->fitnessPolicy->assertFit($dto->replacementMaleCaravanId, $dto->companyId, (string)$replacementBull->identification);

        // 4. Resolver lote de destino del toro saliente
        $destinationBatchId = $dto->destinationBatchId;
        if ($destinationBatchId === null && $dto->reason !== BullReplacementReason::DEATH) {
            // Intentar precargar lote donante original a partir del último movimiento hacia el lote de servicio
            $targetServiceBatchId = $serviceOrder->origin_batch_id ?? $serviceOrder->batch_id;
            $lastEntryMovement = CaravanMovement::where('caravan_id', $retiredBull->id)
                ->where('company_id', $dto->companyId)
                ->where('to_batch_id', $targetServiceBatchId)
                ->latest('movement_date')
                ->first();

            $destinationBatchId = $lastEntryMovement?->from_batch_id;
        }

        if ($destinationBatchId !== null) {
            $destBatch = Batch::withoutGlobalScopes()
                ->where('id', $destinationBatchId)
                ->where('company_id', $dto->companyId)
                ->first();
            if ($destBatch === null) {
                throw ServiceOrderDomainException::domainError("El lote de destino con ID {$destinationBatchId} no existe en esta empresa.");
            }
        }

        // 5. Transacción Atómica
        return DB::transaction(function () use (
            $dto,
            $serviceOrder,
            $retiredBull,
            $activeServiceMale,
            $replacementBull,
            $destinationBatchId
        ) {
            $replacementTimestamp = $dto->replacementDate;
            $targetServiceBatchId = (int) ($serviceOrder->origin_batch_id ?? $serviceOrder->batch_id);

            // A. Actualizar estado del toro saliente en service_order_males
            $retiredStatus = $dto->reason === BullReplacementReason::LOW_LIBIDO_RINCONERO
                ? ServiceOrderMaleStatus::REPLACED->value
                : ServiceOrderMaleStatus::RETIRED_INJURED->value;

            $activeServiceMale->update([
                'status'     => $retiredStatus,
                'retired_at' => $replacementTimestamp,
            ]);

            // B. Mover físicamente al toro saliente
            $previousRetiredBatchId = $retiredBull->batch_id;
            if ($dto->reason === BullReplacementReason::DEATH) {
                $retiredBull->batch_id = null;
                $retiredBull->save();

                CaravanMovement::create([
                    'company_id'    => $dto->companyId,
                    'caravan_id'    => $retiredBull->id,
                    'renspa'        => $retiredBull->renspa ?: 'NO_DEFINIDO',
                    'type'          => 'TRANSFER',
                    'movement_date' => $replacementTimestamp,
                    'from_batch_id' => $previousRetiredBatchId,
                    'to_batch_id'   => null,
                    'observations'  => "Baja por muerte en potrero durante orden {$serviceOrder->code}",
                ]);
            } elseif ($destinationBatchId !== null) {
                $retiredBull->batch_id = $destinationBatchId;
                $retiredBull->save();

                CaravanMovement::create([
                    'company_id'    => $dto->companyId,
                    'caravan_id'    => $retiredBull->id,
                    'renspa'        => $retiredBull->renspa ?: 'NO_DEFINIDO',
                    'type'          => 'TRANSFER',
                    'movement_date' => $replacementTimestamp,
                    'from_batch_id' => $previousRetiredBatchId,
                    'to_batch_id'   => $destinationBatchId,
                    'observations'  => "Retiro de servicio {$serviceOrder->code} por {$dto->reason->label()}" .
                        ($dto->notes ? ": {$dto->notes}" : ''),
                ]);
            }

            // C. Insertar toro suplente en service_order_males
            $scrotalCirc = $replacementBull->bullHealthEvaluation?->scrotal_circumference_cm;
            ServiceOrderMale::create([
                'company_id'                     => $dto->companyId,
                'service_order_id'               => $serviceOrder->id,
                'male_caravan_id'                => $replacementBull->id,
                'snapshot_scrotal_circumference' => $scrotalCirc,
                'service_capacity'               => 'HIGH',
                'status'                         => ServiceOrderMaleStatus::ACTIVE->value,
            ]);

            // D. Mover físicamente al toro suplente hacia el lote de servicio
            $donorBatchId = $replacementBull->batch_id;
            $replacementBull->batch_id = $targetServiceBatchId;
            $replacementBull->save();

            CaravanMovement::create([
                'company_id'    => $dto->companyId,
                'caravan_id'    => $replacementBull->id,
                'renspa'        => $replacementBull->renspa ?: 'NO_DEFINIDO',
                'type'          => 'TRANSFER',
                'movement_date' => $replacementTimestamp,
                'from_batch_id' => $donorBatchId,
                'to_batch_id'   => $targetServiceBatchId,
                'observations'  => "Ingreso como reproductor suplente en orden {$serviceOrder->code}",
            ]);

            // E. Registrar la auditoría en service_order_bull_replacements
            $replacementRecord = ServiceOrderBullReplacement::create([
                'company_id'                  => $dto->companyId,
                'service_order_id'            => $serviceOrder->id,
                'retired_male_caravan_id'     => $retiredBull->id,
                'replacement_male_caravan_id' => $replacementBull->id,
                'replacement_date'            => $replacementTimestamp,
                'reason'                      => $dto->reason->value,
                'destination_batch_id'        => $destinationBatchId,
                'notes'                       => $dto->notes,
                'user_id'                     => $dto->userId,
            ]);

            // F. Recalcular masas en BatchWeightService
            // 1. Lote de servicio (sale un toro, entra un toro)
            $this->batchWeightService->recalculateBatchWeight($targetServiceBatchId, BatchWeightCause::MOVEMENT_IN);

            // 2. Lote destino del toro retirado (si aplica)
            if ($destinationBatchId !== null && $destinationBatchId !== $targetServiceBatchId) {
                $this->batchWeightService->recalculateBatchWeight($destinationBatchId, BatchWeightCause::MOVEMENT_IN);
            }

            // 3. Lote donante del toro suplente (si aplica)
            if ($donorBatchId !== null && $donorBatchId !== $targetServiceBatchId) {
                $this->batchWeightService->recalculateBatchWeight($donorBatchId, BatchWeightCause::MOVEMENT_OUT);
            }

            // G. Auditoría en historial de la orden
            ServiceOrderHistory::create([
                'company_id'       => $dto->companyId,
                'service_order_id' => $serviceOrder->id,
                'action_user_id'   => $dto->userId,
                'from_status'      => $serviceOrder->status,
                'to_status'        => $serviceOrder->status,
                'action_reason'    => "Sustitución de reproductor: #{$retiredBull->identification} retirado por {$dto->reason->label()}, reemplazado por #{$replacementBull->identification}.",
            ]);

            return $replacementRecord->load([
                'retiredMaleCaravan.currentWeight',
                'replacementMaleCaravan.currentWeight',
                'replacementMaleCaravan.bullHealthEvaluation',
                'destinationBatch',
                'user',
            ]);
        });
    }
}
