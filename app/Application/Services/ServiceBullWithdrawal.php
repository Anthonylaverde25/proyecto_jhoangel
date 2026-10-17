<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Core\Enums\BatchWeightCause;
use App\Core\Enums\ServiceExposureExitReason;
use App\Core\Enums\ServiceOrderMaleStatus;
use App\Core\Exceptions\ServiceOrderDomainException;
use App\Core\Interfaces\IServiceOrderRepository;
use App\Core\Services\BatchWeightService;
use App\Models\Batch;
use App\Models\Caravan;
use App\Models\CaravanMovement;
use App\Models\ServiceOrderMale;

/**
 * Takes every active bull of a service out of the serviced batch.
 *
 * Shared by the bull withdrawal (the order then waits for its diagnosis) and by the closing of an
 * order whose bulls leave the same day. It never changes the order status: each caller does.
 * Must run inside the caller's transaction.
 */
final class ServiceBullWithdrawal
{
    public function __construct(
        private readonly IServiceOrderRepository $serviceOrderRepository,
        private readonly BatchWeightService $batchWeightService
    ) {
    }

    /**
     * @param array<int, int> $bullDestinations male_caravan_id => destination batch id
     * @return int number of bulls withdrawn
     *
     * @throws ServiceOrderDomainException
     */
    public function withdraw(
        int $orderId,
        int $companyId,
        int $serviceBatchId,
        string $orderCode,
        string $withdrawalDate,
        array $bullDestinations,
        ?int $defaultDestinationBatchId,
        ?string $observations = null
    ): int {
        $activeServiceMales = ServiceOrderMale::where('service_order_id', $orderId)
            ->where('status', ServiceOrderMaleStatus::ACTIVE->value)
            ->get();

        $activeBulls = Caravan::withoutGlobalScopes()
            ->whereIn('id', $activeServiceMales->pluck('male_caravan_id')->toArray())
            ->where('company_id', $companyId)
            ->get()
            ->keyBy('id');

        // Every destination is resolved before anything moves.
        $resolvedDestinations = [];
        foreach ($activeServiceMales as $serviceMale) {
            $bullId = (int) $serviceMale->male_caravan_id;
            $bull = $activeBulls->get($bullId);

            if ($bull === null) {
                throw ServiceOrderDomainException::domainError("El reproductor con ID {$bullId} no fue encontrado.");
            }

            $destinationId = $bullDestinations[$bullId] ?? $defaultDestinationBatchId;

            // Fallback: the batch the bull came from when he joined the service.
            if ($destinationId === null) {
                $destinationId = CaravanMovement::where('caravan_id', $bullId)
                    ->where('company_id', $companyId)
                    ->where('to_batch_id', $serviceBatchId)
                    ->latest('movement_date')
                    ->first()
                    ?->from_batch_id;
            }

            if ($destinationId === null) {
                throw ServiceOrderDomainException::domainError(
                    "Debe especificar un lote de destino para el toro #{$bull->identification}."
                );
            }

            $destinationExists = Batch::withoutGlobalScopes()
                ->where('id', $destinationId)
                ->where('company_id', $companyId)
                ->exists();

            if (!$destinationExists) {
                throw ServiceOrderDomainException::domainError(
                    "El lote de destino con ID {$destinationId} para el toro #{$bull->identification} no existe en esta empresa."
                );
            }

            $resolvedDestinations[$bullId] = (int) $destinationId;
        }

        foreach ($activeServiceMales as $serviceMale) {
            $bullId = (int) $serviceMale->male_caravan_id;
            $bull = $activeBulls->get($bullId);
            $destinationId = $resolvedDestinations[$bullId];

            $serviceMale->update([
                'status'     => ServiceOrderMaleStatus::COMPLETED->value,
                'retired_at' => $withdrawalDate,
            ]);

            $previousBatchId = $bull->batch_id;
            if ($previousBatchId !== $destinationId) {
                $bull->batch_id = $destinationId;
                $bull->save();

                CaravanMovement::create([
                    'company_id'    => $companyId,
                    'caravan_id'    => $bull->id,
                    'renspa'        => $bull->renspa ?: 'NO_DEFINIDO',
                    'type'          => 'TRANSFER',
                    'movement_date' => $withdrawalDate,
                    'from_batch_id' => $previousBatchId,
                    'to_batch_id'   => $destinationId,
                    'observations'  => "Retiro total de torada por finalización de orden {$orderCode}" .
                        ($observations ? ": {$observations}" : ''),
                ]);
            }
        }

        // Without bulls there is no exposure: it ends for every female still exposed.
        $this->serviceOrderRepository->closeOpenExposures(
            $orderId,
            substr($withdrawalDate, 0, 10),
            ServiceExposureExitReason::BULLS_WITHDRAWN
        );

        $this->batchWeightService->recalculateBatchWeight($serviceBatchId, BatchWeightCause::MOVEMENT_OUT);
        foreach (array_unique(array_values($resolvedDestinations)) as $destinationId) {
            if ($destinationId !== $serviceBatchId) {
                $this->batchWeightService->recalculateBatchWeight($destinationId, BatchWeightCause::MOVEMENT_IN);
            }
        }

        return count($activeServiceMales);
    }
}
