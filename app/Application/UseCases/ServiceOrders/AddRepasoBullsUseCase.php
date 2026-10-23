<?php

declare(strict_types=1);

namespace App\Application\UseCases\ServiceOrders;

use App\Core\Entities\ServiceOrderEntity;
use App\Core\Enums\AnimalSex;
use App\Core\Enums\BatchWeightCause;
use App\Core\Enums\ServiceOrderStatus;
use App\Core\Exceptions\ServiceOrderDomainException;
use App\Core\Interfaces\IServiceOrderRepository;
use App\Core\Services\BatchWeightService;
use App\Core\Services\BullServiceFitnessPolicy;
use App\Models\Caravan;
use App\Models\ServiceOrderHistory;
use App\Models\ServiceOrderMale;
use Illuminate\Support\Facades\DB;

/**
 * "Agregar toros de repaso" (semen S-B, P3, IA10): bulls join an AI order still in service, with
 * the same admission guard and the same move into the batch as a natural service. From then on its
 * empties are provisional until "Retirar torada", which also ends the service.
 */
final class AddRepasoBullsUseCase
{
    public function __construct(
        private readonly IServiceOrderRepository $orders,
        private readonly BullServiceFitnessPolicy $fitnessPolicy,
        private readonly BatchWeightService $batchWeightService,
    ) {
    }

    /**
     * @param int[] $maleCaravanIds
     *
     * @throws ServiceOrderDomainException
     */
    public function __invoke(int $orderId, int $companyId, int $userId, array $maleCaravanIds, string $joinedAt): ServiceOrderEntity
    {
        $order = $this->orders->findById($orderId, $companyId)
            ?? throw ServiceOrderDomainException::domainError('La orden de servicio no existe.');
        if (!$order->isInsemination() || $order->getStatus() !== ServiceOrderStatus::APPROVED) {
            throw ServiceOrderDomainException::domainError('Los toros de repaso se agregan a una orden de IA en servicio.');
        }

        $maleCaravanIds = array_values(array_unique(array_map('intval', $maleCaravanIds)));
        if ($maleCaravanIds === []) {
            throw ServiceOrderDomainException::domainError('Seleccioná al menos un toro de repaso.');
        }
        $joinedAt = substr($joinedAt, 0, 10);
        $start = substr($order->getActualStartDate() ?? $order->getPlannedStartDate(), 0, 10);
        if ($joinedAt < $start || $joinedAt > now()->toDateString()) {
            throw ServiceOrderDomainException::domainError("La fecha de ingreso tiene que estar entre el inicio del servicio ({$start}) y hoy.");
        }

        $bulls = Caravan::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('id', $maleCaravanIds)
            ->with('bullHealthEvaluation')
            ->get()
            ->keyBy('id');

        $sanitaryStatusByBull = [];
        foreach ($maleCaravanIds as $maleId) {
            $bull = $bulls->get($maleId)
                ?? throw ServiceOrderDomainException::domainError("El reproductor macho con ID {$maleId} no fue encontrado.");
            $sex = $bull->sex instanceof AnimalSex ? $bull->sex->value : (string) $bull->sex;
            if ($sex !== AnimalSex::MALE->value) {
                throw ServiceOrderDomainException::invalidAnimalSex($maleId, 'male', $sex);
            }
            if (in_array($maleId, $order->getActiveMaleCaravanIds(), true)) {
                throw ServiceOrderDomainException::domainError("El toro {$bull->identification} ya está en esta orden.");
            }
            $sanitaryStatusByBull[$maleId] = $this->fitnessPolicy->assertFit($maleId, $companyId, (string) $bull->identification);
        }

        $conflicts = $this->orders->findActiveOrdersByCaravans($maleCaravanIds, $companyId);
        if ($conflicts !== []) {
            throw ServiceOrderDomainException::activeOrderConflict('caravan', (int) reset($conflicts));
        }

        DB::transaction(function () use ($order, $companyId, $userId, $bulls, $sanitaryStatusByBull, $maleCaravanIds, $joinedAt): void {
            foreach ($maleCaravanIds as $maleId) {
                $bull = $bulls->get($maleId);
                ServiceOrderMale::create([
                    'company_id'                     => $companyId,
                    'service_order_id'               => $order->getId(),
                    'male_caravan_id'                => $maleId,
                    'joined_at'                      => $joinedAt,
                    'snapshot_scrotal_circumference' => $bull->bullHealthEvaluation?->scrotal_circumference_cm,
                    'sanitary_status_at_entry'       => $sanitaryStatusByBull[$maleId]?->value,
                    'service_capacity'               => 'HIGH',
                    'status'                         => 'ACTIVE',
                ]);
            }

            $donorBatchIds = $this->orders->moveBullsToBatch($maleCaravanIds, $order->getBatchId(), $companyId, $joinedAt, $order->getCode());
            foreach ($donorBatchIds as $donorBatchId) {
                $this->batchWeightService->recalculateBatchWeight($donorBatchId, BatchWeightCause::MOVEMENT_OUT);
            }
            $this->batchWeightService->recalculateBatchWeight($order->getBatchId(), BatchWeightCause::MOVEMENT_IN);

            $labels = implode(', ', array_map(fn (int $id) => (string) $bulls->get($id)->identification, $maleCaravanIds));
            ServiceOrderHistory::create([
                'company_id'       => $companyId,
                'service_order_id' => $order->getId(),
                'action_user_id'   => $userId,
                'from_status'      => ServiceOrderStatus::APPROVED->value,
                'to_status'        => ServiceOrderStatus::APPROVED->value,
                'action_reason'    => "Toros de repaso ingresados el {$joinedAt}: {$labels}",
            ]);
        });

        return $this->orders->findById($orderId, $companyId)
            ?? throw ServiceOrderDomainException::domainError('Error al recuperar la orden de servicio.');
    }
}
