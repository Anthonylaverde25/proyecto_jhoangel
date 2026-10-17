<?php

declare(strict_types=1);

namespace App\Application\UseCases\ServiceOrders;

use App\Application\DTOs\ServiceOrders\ReplaceServiceBullDTO;
use App\Application\Services\ServiceBullRetirement;
use App\Application\UseCases\Veterinary\RaiseSanitaryExposureAlertUseCase;
use App\Core\Enums\AnimalSex;
use App\Core\Enums\BatchWeightCause;
use App\Core\Enums\BullReplacementReason;
use App\Core\Enums\ServiceOrderMaleStatus;
use App\Core\Exceptions\ServiceOrderDomainException;
use App\Core\Interfaces\IServiceOrderRepository;
use App\Core\Services\BatchWeightService;
use App\Core\Services\BullServiceFitnessPolicy;
use App\Models\Caravan;
use App\Models\CaravanMovement;
use App\Models\ServiceOrderBullReplacement;
use App\Models\ServiceOrderHistory;
use App\Models\ServiceOrderMale;
use Illuminate\Support\Facades\DB;

final class ReplaceServiceBullUseCase
{
    public function __construct(
        private readonly IServiceOrderRepository $serviceOrderRepository,
        private readonly BullServiceFitnessPolicy $fitnessPolicy,
        private readonly BatchWeightService $batchWeightService,
        private readonly ServiceBullRetirement $retirement,
        private readonly RaiseSanitaryExposureAlertUseCase $raiseExposureAlert
    ) {
    }

    /**
     * @throws ServiceOrderDomainException
     */
    public function __invoke(ReplaceServiceBullDTO $dto): ServiceOrderBullReplacement
    {
        // 1-2. The order must be running and the outgoing bull active in it.
        [$serviceOrder, $retiredBull, $activeServiceMale] = $this->retirement->loadActiveBull(
            $dto->serviceOrderId,
            $dto->retiredMaleCaravanId,
            $dto->companyId
        );

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
        $replacementSanitaryStatus = $this->fitnessPolicy->assertFit($dto->replacementMaleCaravanId, $dto->companyId, (string)$replacementBull->identification);

        // 4. Resolver lote de destino del toro saliente
        $destinationBatchId = $this->retirement->resolveDestination(
            $serviceOrder,
            $retiredBull,
            $dto->reason,
            $dto->destinationBatchId,
            $dto->companyId
        );

        // 5. Transacción Atómica
        $replacement = DB::transaction(function () use (
            $dto,
            $serviceOrder,
            $retiredBull,
            $activeServiceMale,
            $replacementBull,
            $destinationBatchId,
            $replacementSanitaryStatus
        ) {
            $replacementTimestamp = $dto->replacementDate;
            $targetServiceBatchId = (int) $serviceOrder->batch_id;

            // A-B. Close the outgoing bull's stay and move him out of the serviced batch.
            $this->retirement->retire(
                $serviceOrder,
                $retiredBull,
                $activeServiceMale,
                $dto->reason,
                $replacementTimestamp,
                $destinationBatchId,
                $dto->notes,
                $dto->companyId
            );

            // C. Insertar toro suplente en service_order_males
            $scrotalCirc = $replacementBull->bullHealthEvaluation?->scrotal_circumference_cm;
            ServiceOrderMale::create([
                'company_id'                     => $dto->companyId,
                'service_order_id'               => $serviceOrder->id,
                'male_caravan_id'                => $replacementBull->id,
                'joined_at'                      => substr((string) $replacementTimestamp, 0, 10),
                'snapshot_scrotal_circumference' => $scrotalCirc,
                'sanitary_status_at_entry'       => $replacementSanitaryStatus?->value,
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

        // An open exposure alert of the outgoing bull now knows when he left the females.
        ($this->raiseExposureAlert)((int) $retiredBull->id, $dto->companyId);

        return $replacement;
    }
}
